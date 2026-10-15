<?php

declare(strict_types=1);

namespace Drupal\contract_content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\contract_content\EventSubscriber\ExportSubscriber;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\DefaultContent\InvalidEntityException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;

/**
 * Exports the site's content to a recipe, and imports it again.
 *
 * Core's recipe importer only creates content: an entity whose UUID already
 * exists is skipped (\Drupal\Core\Recipe\RecipeRunner uses Existing::Skip).
 * import() creates new entities the same way, then updates existing ones in
 * place from the same YAML, so they keep their IDs and gain a new revision.
 *
 * Building an entity from the YAML uses the core importer's private toEntity(),
 * so that references by UUID, link fields, Canvas component trees and crops
 * are resolved exactly as they are on create. The default content API is
 * experimental; check this class after core updates.
 */
class ContentUpdater {

  /**
   * The content to export: entity type ID => bundles (empty for all).
   *
   * Media, files and crops are exported as dependencies of what uses them.
   * Newsletter issues (simplenews_issue nodes) are left out: their send status
   * belongs to the site that sent them. Users are never exported, see
   * ExportSubscriber.
   */
  const EXPORT = [
    'node' => ['page', 'guest'],
    'canvas_page' => [],
    'menu_link_content' => [],
    'redirect' => [],
  ];

  /**
   * Entity keys whose fields are never copied onto existing content.
   */
  const KEPT_KEYS = [
    'id',
    'uuid',
    'revision',
    'bundle',
    'langcode',
    'default_langcode',
    'revision_translation_affected',
    'owner',
  ];

  /**
   * Fields that are never copied onto existing content, by entity type.
   *
   * Media thumbnails are not exported; media generates them from its source.
   */
  const KEPT_FIELDS = [
    'media' => ['thumbnail'],
  ];

  /**
   * Revision log message for updated content.
   */
  const REVISION_LOG = 'Updated from the contract_content recipe.';

  /**
   * The UUIDs in the content being imported, by entity type.
   *
   * @var array<string, array<string, true>>
   */
  protected array $exportedUuids = [];

  public function __construct(
    protected Exporter $exporter,
    protected Importer $importer,
    protected AdminAccountSwitcher $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected FileSystemInterface $fileSystem,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * Replaces the content in a directory with a fresh export.
   *
   * @param string $dir
   *   The recipe's content directory.
   *
   * @return int
   *   The number of entities exported, including dependencies.
   */
  public function export(string $dir): int {
    if (is_dir($dir)) {
      $this->fileSystem->deleteRecursive($dir);
    }
    $count = 0;
    foreach (self::EXPORT as $entity_type_id => $bundles) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $storage->getQuery()->accessCheck(FALSE)->sort($storage->getEntityType()->getKey('id'));
      if ($bundles) {
        $query->condition($storage->getEntityType()->getKey('bundle'), $bundles, 'IN');
      }
      foreach ($storage->loadMultiple($query->execute()) as $entity) {
        $count += $this->exporter->exportWithDependencies($entity, $dir);
      }
    }

    // A crop references its image, not the other way round, so crops are not
    // exported as dependencies. Add the crops of the exported images.
    if ($this->entityTypeManager->hasDefinition('crop')) {
      foreach ($this->entityTypeManager->getStorage('crop')->loadMultiple() as $crop) {
        $target = $this->entityTypeManager->getStorage($crop->entity_type->value)->load($crop->entity_id->value);
        if ($target && file_exists("$dir/{$target->getEntityTypeId()}/{$target->uuid()}.yml")) {
          $count += $this->exporter->exportWithDependencies($crop, $dir);
        }
      }
    }
    return $count;
  }

  /**
   * Imports content: creates new entities and updates existing ones.
   *
   * @param string $dir
   *   The recipe's content directory.
   *
   * @return array{created: int, updated: int, adopted: int}
   *   How many entities were created and updated, and how many of the updated
   *   ones were matched by file instead of UUID (see adopt()).
   */
  public function import(string $dir): array {
    $content = new Finder($dir);
    $this->exportedUuids = [];
    foreach ($content->data as $uuid => $decoded) {
      $this->exportedUuids[$decoded['_meta']['entity_type']][$uuid] = TRUE;
    }

    // Note what exists before the core importer creates the rest. The data is
    // in dependency order, so files are adopted before their media and crops.
    $existing = [];
    $adopted = 0;
    foreach ($content->data as $uuid => $decoded) {
      if ($this->entityRepository->loadEntityByUuid($decoded['_meta']['entity_type'], $uuid)) {
        $existing[$uuid] = TRUE;
      }
      elseif ($this->adopt($decoded)) {
        $existing[$uuid] = TRUE;
        $adopted++;
      }
    }

    $this->importer->importContent($content, Existing::Skip);
    $this->removeDuplicateCrops(array_diff_key($content->data, $existing));

    $to_entity = \Closure::bind(fn (array $data): ContentEntityInterface => $this->toEntity($data), $this->importer, Importer::class);
    $this->accountSwitcher->switchToAdministrator();
    try {
      foreach ($content->data as $uuid => $decoded) {
        if (isset($existing[$uuid])) {
          $this->update($decoded, $to_entity($decoded));
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    return [
      'created' => count($content->data) - count($existing),
      'updated' => count($existing),
      'adopted' => $adopted,
    ];
  }

  /**
   * Gives the exported UUID to an existing copy of the same file.
   *
   * An image uploaded separately on each site has a different UUID on each,
   * and core cannot create the exported file because its URI is taken. The
   * existing file is matched by URI, its media by source file, and its crops
   * by image and crop type. The match takes the exported UUID, keeping its ID
   * so that anything using it is unaffected, and is then updated as usual.
   *
   * @param array $decoded
   *   The decoded YAML of an entity whose UUID is not on this site.
   *
   * @return bool
   *   TRUE if an existing entity was given the UUID.
   */
  protected function adopt(array $decoded): bool {
    ['entity_type' => $entity_type_id, 'uuid' => $uuid] = $decoded['_meta'];
    $values = $decoded['default'] ?? [];
    $properties = match ($entity_type_id) {
      'file' => ['uri' => $values['uri'][0]['value'] ?? NULL],
      'media' => $this->mediaSourceProperties($decoded),
      'crop' => [
        'type' => $decoded['_meta']['bundle'] ?? NULL,
        'entity_type' => $values['entity_type'][0]['value'] ?? NULL,
        'entity_id' => $this->idOf($values['entity_type'][0]['value'] ?? NULL, $values['entity_id'][0]['uuid'] ?? NULL),
      ],
      default => [],
    };
    if (!$properties || in_array(NULL, $properties, TRUE)) {
      return FALSE;
    }

    $matches = $this->entityTypeManager->getStorage($entity_type_id)->loadByProperties($properties);
    // Only adopt an unambiguous match, and never one that is itself exported
    // under its own UUID.
    if (count($matches) !== 1) {
      return FALSE;
    }
    $entity = reset($matches);
    assert($entity instanceof ContentEntityInterface);
    if ($this->isInExport($entity)) {
      return FALSE;
    }
    $entity->set('uuid', $uuid);
    if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(FALSE);
    }
    $entity->setSyncing(TRUE)->save();
    return TRUE;
  }

  /**
   * The properties identifying a media item by its bundle and source file.
   *
   * @param array $decoded
   *   The decoded YAML of a media item.
   *
   * @return array
   *   Properties for loadByProperties(), or an empty array.
   */
  protected function mediaSourceProperties(array $decoded): array {
    $bundle = $decoded['_meta']['bundle'] ?? NULL;
    $media_type = $bundle ? $this->entityTypeManager->getStorage('media_type')->load($bundle) : NULL;
    if (!$media_type) {
      return [];
    }
    $field = $media_type->getSource()->getSourceFieldDefinition($media_type)?->getName();
    $file_uuid = $field ? ($decoded['default'][$field][0]['entity'] ?? NULL) : NULL;
    return [
      'bundle' => $bundle,
      $field . '.target_id' => $this->idOf('file', $file_uuid),
    ];
  }

  /**
   * The ID of an entity identified by UUID, or NULL.
   */
  protected function idOf(?string $entity_type_id, ?string $uuid): int|string|null {
    if (!$entity_type_id || !$uuid) {
      return NULL;
    }
    return $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid)?->id();
  }

  /**
   * Whether this import has its own data for an entity.
   */
  protected function isInExport(ContentEntityInterface $entity): bool {
    return isset($this->exportedUuids[$entity->getEntityTypeId()][$entity->uuid()]);
  }

  /**
   * Deletes crops made automatically for images the import just created.
   *
   * Focal Point gives a new image a default crop when it is saved, before the
   * exported crop for that image is imported, leaving two crops of the same
   * type for one image. The exported one is kept.
   *
   * @param array $created
   *   The decoded YAML of the entities the core importer created, by UUID.
   */
  protected function removeDuplicateCrops(array $created): void {
    if (!$this->entityTypeManager->hasDefinition('crop')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('crop');
    foreach ($created as $uuid => $decoded) {
      if ($decoded['_meta']['entity_type'] !== 'crop') {
        continue;
      }
      $crop = $this->entityRepository->loadEntityByUuid('crop', $uuid);
      if (!$crop) {
        continue;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $crop->bundle())
        ->condition('uri', $crop->uri->value)
        ->condition('uuid', $uuid, '<>')
        ->execute();
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Updates an existing entity from its exported data.
   *
   * @param array $decoded
   *   The decoded YAML, including _meta.
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A new, unsaved entity built from the same data.
   */
  protected function update(array $decoded, ContentEntityInterface $source): void {
    ['entity_type' => $entity_type_id, 'uuid' => $uuid, 'path' => $path] = $decoded['_meta'];
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    assert($entity instanceof ContentEntityInterface);

    $exported = array_keys($decoded['default'] ?? []);
    $changed = [];
    foreach ($source->getTranslationLanguages() as $langcode => $language) {
      $from = $source->getTranslation($langcode);
      $to = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity->addTranslation($langcode);
      foreach ($from->getFields() as $name => $items) {
        if (!$this->isCopied($entity, $name, $exported) || (!$from->isDefaultTranslation() && !$items->getFieldDefinition()->isTranslatable())) {
          continue;
        }
        $values = $items->getValue();
        // Keep the alias's ID so the existing alias is updated, not duplicated.
        if ($items->getFieldDefinition()->getType() === 'path' && isset($to->get($name)->pid)) {
          foreach ($values as &$value) {
            $value['pid'] = $to->get($name)->pid;
          }
          unset($value);
        }
        if ($to->get($name)->getValue() != $values) {
          $changed[$name] = $name;
        }
        $to->set($name, $values);
      }
    }

    if ($entity instanceof FileInterface) {
      $this->updateFile(dirname($path), $entity);
    }
    if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision();
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage(self::REVISION_LOG);
        $entity->setRevisionCreationTime($this->time->getRequestTime());
        $entity->setRevisionUserId($this->currentUser->id());
      }
    }

    // Only report problems in what the import changed: content can already
    // fail validation on the site (an SVG thumbnail fails the image extension
    // check), and that is not this import's to fix.
    $violations = $entity->validate();
    $violations->filterByFields(array_diff(array_keys($entity->getFieldDefinitions()), $changed));
    if (count($violations) > 0) {
      throw new InvalidEntityException($violations, $path);
    }
    $entity->save();
  }

  /**
   * Whether a field is copied from the export onto an existing entity.
   *
   * Exported fields are copied. So are stored fields the exporter leaves out
   * only because they are empty, so that emptying a field is carried over too.
   * Fields the exporter never exports are kept: IDs and other entity keys,
   * created and changed times, revision metadata, and user references.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The existing entity.
   * @param string $name
   *   The field name.
   * @param string[] $exported
   *   The names of the fields in the export.
   */
  protected function isCopied(ContentEntityInterface $entity, string $name, array $exported): bool {
    $entity_type = $entity->getEntityType();
    $kept = array_merge(array_values($entity_type->getRevisionMetadataKeys()), self::KEPT_FIELDS[$entity_type->id()] ?? []);
    foreach (self::KEPT_KEYS as $key) {
      if ($entity_type->hasKey($key)) {
        $kept[] = $entity_type->getKey($key);
      }
    }
    if (in_array($name, $kept, TRUE)) {
      return FALSE;
    }
    if (in_array($name, $exported, TRUE)) {
      return TRUE;
    }
    $definition = $entity->getFieldDefinition($name);
    return !$definition->isComputed()
      && !$definition->isReadOnly()
      && !in_array($definition->getType(), ['created', 'changed'], TRUE)
      && !ExportSubscriber::referencesUsers($definition);
  }

  /**
   * Replaces a file's contents if the exported copy differs.
   *
   * @param string $dir
   *   The directory of the file's YAML, where the exporter put the file.
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   */
  protected function updateFile(string $dir, FileInterface $file): void {
    $uri = $file->getFileUri();
    $source = $dir . '/' . basename($uri);
    if (!file_exists($source) || (file_exists($uri) && hash_file('sha256', $source) === hash_file('sha256', $uri))) {
      return;
    }
    $this->fileSystem->prepareDirectory(dirname($uri), FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->copy($source, $uri, FileExists::Replace);
    $file->setSize(filesize($uri));
    if (function_exists('image_path_flush')) {
      image_path_flush($uri);
    }
  }

}
