<?php

declare(strict_types=1);

namespace Drupal\registration_canvas;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * Wraps the start of headings inside HTML in a styleable span.
 *
 * Works on each heading in isolation, so the rest of the HTML (form inputs,
 * scripts, attributes) is passed through byte for byte. The start of a heading
 * is chosen by HeadingSplitter.
 */
class HeadingHighlighter {

  const LEAD_CLASS = 'c-heading__lead';

  public function __construct(
    protected HeadingSplitter $splitter,
  ) {
  }

  /**
   * Highlights the start of the headings at the given levels.
   *
   * @param string $html
   *   HTML that may contain headings.
   * @param int[] $levels
   *   Heading levels to process, e.g. [2, 3].
   *
   * @return string
   *   The HTML, with the start of each matching heading wrapped in
   *   <span class="c-heading__lead">.
   */
  public function highlight(string $html, array $levels = [2, 3]): string {
    $levels = array_values(array_filter(array_map('intval', $levels), fn (int $level): bool => $level >= 1 && $level <= 6));
    if (!$levels || stripos($html, '<h') === FALSE) {
      return $html;
    }
    $pattern = '#<h(' . implode('|', $levels) . ')(\s[^>]*)?>.*?</h\1>#is';
    return preg_replace_callback($pattern, fn (array $match): string => $this->highlightHeading($match[0]), $html) ?? $html;
  }

  /**
   * Highlights the start of some plain text, for use as a title or label.
   *
   * @param string $text
   *   Plain text (it is escaped here).
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   Safe markup with the start wrapped in
   *   <span class="c-heading__lead">, or the text unchanged if blank.
   */
  public function highlightText(string $text): Markup|string {
    [$lead, $rest] = $this->splitter->split($text);
    if ($lead === '') {
      return $text;
    }
    return Markup::create(sprintf('<span class="%s">%s</span>%s', self::LEAD_CLASS, Html::escape($lead), Html::escape($rest)));
  }

  /**
   * Highlights the start of a single heading element.
   *
   * @param string $heading
   *   One complete heading element, such as "<h2>Hotel</h2>".
   */
  protected function highlightHeading(string $heading): string {
    // Leave a heading that already has a highlighted start alone.
    if (str_contains($heading, self::LEAD_CLASS)) {
      return $heading;
    }

    $dom = Html::load($heading);
    $xpath = new \DOMXPath($dom);
    // The first piece of visible text, which may be inside a link or
    // emphasis.
    $first = $xpath->query('//body//text()[normalize-space()]')->item(0);
    if (!$first instanceof \DOMText) {
      return $heading;
    }
    $value = $first->nodeValue;
    $trimmed = ltrim($value);
    $indent = substr($value, 0, strlen($value) - strlen($trimmed));
    [$lead, $rest] = $this->splitter->split($trimmed);
    if ($lead === '') {
      return $heading;
    }

    $span = $dom->createElement('span');
    $span->setAttribute('class', self::LEAD_CLASS);
    $span->appendChild($dom->createTextNode($lead));
    $parent = $first->parentNode;
    if ($indent !== '') {
      $parent->insertBefore($dom->createTextNode($indent), $first);
    }
    $parent->insertBefore($span, $first);
    if ($rest === '') {
      $parent->removeChild($first);
    }
    else {
      $first->nodeValue = $rest;
    }
    return Html::serialize($dom);
  }

}
