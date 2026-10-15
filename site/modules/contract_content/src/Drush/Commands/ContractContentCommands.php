<?php

declare(strict_types=1);

namespace Drupal\contract_content\Drush\Commands;

use Drupal\contract_content\ContentUpdater;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

/**
 * Exports and imports the content of the contract_content recipe.
 */
final class ContractContentCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected ContentUpdater $updater,
  ) {
    parent::__construct();
  }

  /**
   * Exports pages, Canvas pages, menus and redirects to the recipe.
   */
  #[CLI\Command(name: 'contract-content:export', aliases: ['cc-export'])]
  #[CLI\Option(name: 'dir', description: 'The content directory to replace. Defaults to the contract_content recipe in the site project.')]
  #[CLI\Usage(name: 'drush contract-content:export', description: 'Replace recipes/contract_content/content with this site\'s content.')]
  public function export(array $options = ['dir' => NULL]): void {
    $dir = $options['dir'] ?? $this->defaultDir();
    $count = $this->updater->export($dir);
    $this->logger()->success(dt('Exported @count entities to @dir.', ['@count' => $count, '@dir' => $dir]));
  }

  /**
   * Imports the recipe's content, updating content that already exists.
   */
  #[CLI\Command(name: 'contract-content:import', aliases: ['cc-import'])]
  #[CLI\Option(name: 'dir', description: 'The content directory to import. Defaults to the contract_content recipe in the site project.')]
  #[CLI\Usage(name: 'drush contract-content:import', description: 'Create new content from the recipe and overwrite existing content with the same UUID.')]
  public function import(array $options = ['dir' => NULL]): void {
    $dir = $options['dir'] ?? $this->defaultDir();
    if (!is_dir($dir)) {
      throw new \RuntimeException("Content directory $dir does not exist.");
    }
    if (!$this->io()->confirm(dt('Overwrite existing content on this site with the content in @dir?', ['@dir' => $dir]))) {
      throw new UserAbortException();
    }
    $result = $this->updater->import($dir);
    $this->logger()->success(dt('Created @created and updated @updated entities (@adopted of them matched by file).', [
      '@created' => $result['created'],
      '@updated' => $result['updated'],
      '@adopted' => $result['adopted'],
    ]));
  }

  /**
   * The recipe's content directory: recipes/ beside the site directory.
   */
  protected function defaultDir(): string {
    $site_path = \Drupal::getContainer()->getParameter('site.path');
    return dirname(realpath(DRUPAL_ROOT . '/' . $site_path)) . '/recipes/contract_content/content';
  }

}
