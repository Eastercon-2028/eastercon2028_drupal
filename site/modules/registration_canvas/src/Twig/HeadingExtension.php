<?php

declare(strict_types=1);

namespace Drupal\registration_canvas\Twig;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\registration_canvas\HeadingSplitter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig filter that wraps the start of a heading in a styleable span.
 */
class HeadingExtension extends AbstractExtension {

  public function __construct(
    protected HeadingSplitter $splitter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter('heading_lead', $this->headingLead(...), ['is_safe' => ['html']]),
    ];
  }

  /**
   * Wraps the start of a heading in <span class="c-heading__lead">.
   *
   * @param mixed $text
   *   Plain-text heading. It is escaped here, so markup in it is shown as
   *   text.
   * @param string $class
   *   Class for the wrapping span.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The heading with its start wrapped, or an empty string for blank text.
   */
  public function headingLead(mixed $text, string $class = 'c-heading__lead'): MarkupInterface|string {
    [$lead, $rest] = $this->splitter->split((string) $text);
    if ($lead === '') {
      return '';
    }
    return Markup::create(sprintf(
      '<span class="%s">%s</span>%s',
      Html::escape($class),
      Html::escape($lead),
      Html::escape($rest),
    ));
  }

}
