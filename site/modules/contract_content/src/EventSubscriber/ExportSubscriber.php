<?php

declare(strict_types=1);

namespace Drupal\contract_content\EventSubscriber;

use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps user accounts out of exported content.
 *
 * Users on this site are members, so an exported account would put personal
 * details and a password hash into git. Fields that reference users (author,
 * revision author, owner) are not exported; the importer makes imported
 * content belong to the administrator, and ContentUpdater leaves the owner of
 * existing content alone.
 */
class ExportSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreExportEvent::class => 'preExport'];
  }

  /**
   * Marks every field that references a user as not exportable.
   */
  public function preExport(PreExportEvent $event): void {
    foreach ($event->entity->getFieldDefinitions() as $name => $definition) {
      if (self::referencesUsers($definition)) {
        $event->setExportable($name, FALSE);
      }
    }
  }

  /**
   * Whether a field is an entity reference to users.
   */
  public static function referencesUsers(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference'
      && $definition->getFieldStorageDefinition()->getSetting('target_type') === 'user';
  }

}
