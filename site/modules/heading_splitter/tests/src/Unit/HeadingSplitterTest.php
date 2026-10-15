<?php

declare(strict_types=1);

namespace Drupal\Tests\heading_splitter\Unit;

use Drupal\heading_splitter\HeadingSplitter;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the heading split rule.
 *
 * @coversDefaultClass \Drupal\heading_splitter\HeadingSplitter
 * @group heading_splitter
 */
class HeadingSplitterTest extends UnitTestCase {

  /**
   * @covers ::split
   * @dataProvider providerSplit
   */
  public function testSplit(string $text, string $lead, string $rest): void {
    $this->assertSame([$lead, $rest], (new HeadingSplitter())->split($text));
  }

  /**
   * Data provider for testSplit().
   */
  public static function providerSplit(): array {
    return [
      'blank' => ['', '', ''],
      'multi-word takes first word' => ['Contract Eastercon', 'Contract', ' Eastercon'],
      'apostrophe kept' => ["What's on", "What's", ' on'],
      'hyphen kept' => ['Check-in times', 'Check-in', ' times'],
      'long first word in multi-word title uses syllable rule' => ['Interpretation details', 'Inter', 'pretation details'],
      'hand-chosen start' => ['Registration details', 'Reg', 'istration details'],
      'hand-chosen start, single word' => ['Registration', 'Reg', 'istration'],
      'single vowel group is whole word' => ['Home', 'Home', ''],
      'one consonant splits before it' => ['Hotel', 'Ho', 'tel'],
            'two consonants split after first' => ['Hilton', 'Hil', 'ton'],
      'three consonants split after first' => ['Contract', 'Con', 'tract'],
      'leading digraph stays with what follows' => ['Mother', 'Mo', 'ther'],
      'ck stays with what precedes' => ['Tickets', 'Tick', 'ets'],
      'y counts as a vowel' => ['Yesterday', 'Yes', 'terday'],
      'no later vowel group is whole word' => ['Rhythm', 'Rhythm', ''],
      'digits only' => ['2028', '2028', ''],
      'hyphen after a prefix is the break' => ['Anti-harassment policy', 'Anti', '-harassment policy'],
      'hyphen break in a single word' => ['Anti-Harassment', 'Anti', '-Harassment'],
      'short hyphenated prefix' => ['Co-operation', 'Co', '-operation'],
      'single hyphenated word' => ['Check-in', 'Check', '-in'],
            'comma stays out of the start' => ['Privacy, Photographs', 'Privacy', ', Photographs'],
      'exclamation mark stays out' => ['Home!', 'Home', '!'],
      'punctuation after a syllable split' => ['Hotel?', 'Ho', 'tel?'],
      'apostrophe stays in the start' => ["What's, on", "What's", ', on'],
      'long word with a very short start is extended' => ['Interpretation', 'Inter', 'pretation'],
      'long word extended, second example' => ['Understanding', 'Under', 'standing'],
      'word followed by a number splits like a single word' => ['Member 1', 'Mem', 'ber 1'],
      'label with a number, short word' => ['Hotel 2', 'Ho', 'tel 2'],
      'word followed by another word is still taken whole' => ['Member Portal', 'Member', ' Portal'],
      'hand-chosen start applies to a prefix before a hyphen' => ['Registration-desk', 'Reg', 'istration-desk'],
      'shorter word keeps its short start' => ['Financial', 'Fi', 'nancial'],
      'leading whitespace dropped' => ['  Hotel', 'Ho', 'tel'],
    ];
  }

}
