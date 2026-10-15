<?php

declare(strict_types=1);

namespace Drupal\heading_splitter\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\heading_splitter\HeadingHighlighter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wraps the start of headings in text in a styleable span.
 *
 * Does for headings typed into rich text what the heading_lead Twig filter
 * does for the heading component: the start of the heading, chosen by
 * HeadingSplitter (via HeadingHighlighter), goes in <span class="c-heading__lead">. Which heading
 * levels are affected is the "levels" setting (h2 and h3 by default).
 *
 * Runs after "Limit allowed HTML", which would strip the span, so give it a
 * higher weight than that filter.
 */
#[Filter(
  id: 'heading_splitter_lead',
  title: new TranslatableMarkup('Highlight the start of headings'),
  description: new TranslatableMarkup('Wraps the first word or syllable of h2 and h3 headings in a span the theme can style.'),
  type: FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
  settings: ['levels' => [2, 3]],
)]
class HeadingLeadFilter extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected HeadingHighlighter $highlighter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('heading_splitter.highlighter'));
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    return new FilterProcessResult($this->highlighter->highlight($text, $this->settings['levels'] ?? []));
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE): string {
    return (string) $this->t('The start of h2 and h3 headings is picked out by the theme.');
  }

}
