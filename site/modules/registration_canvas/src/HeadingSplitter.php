<?php

declare(strict_types=1);

namespace Drupal\registration_canvas;

/**
 * Splits the start of a heading off so it can be styled differently.
 *
 * The rule, in order:
 * - Multi-word heading: the whole first word, including any apostrophes and
 *   hyphens ("WHAT'S" on), unless it has more than MAX_WORD_LETTERS letters,
 *   in which case the syllable rule below applies to that word.
 * - Single-word heading: the first syllable, found approximately with a
 *   vowel-group rule rather than a hyphenation dictionary.
 *
 * Syllable rule: take everything up to the end of the first vowel group, then
 * look at the consonants before the next vowel group:
 * - No later vowel group: the whole word.
 * - One consonant: split before it ("Ho|tel", "Re|gistration").
 * - Two or more: split after the first ("Hil|ton", "Con|tract"), but keep the
 *   digraphs sh, ch, th, ph and wh together with the following letters
 *   ("Mo|ther"), and ck and ng with the preceding ones ("Tick|ets").
 * A final silent "e" does not count as a vowel group, so "Home" stays whole.
 *
 * A word followed only by a number ("Member 1") is a label, and splits like a
 * single word: "Mem|ber 1".
 *
 * START_OVERRIDES fixes the start of particular words by hand.
 *
 * In a long word (LONG_WORD_LETTERS letters or more) a start of
 * SHORT_START_LETTERS letters or fewer looks unbalanced, so it is extended by
 * one more syllable: "Inter|pretation" rather than "In|terpretation".
 *
 * Sentence punctuation (, . ; : ! ? …) directly after the start is left out of
 * it: "Privacy" then ", Photographs".
 *
 * A hyphen after a short prefix is the break instead, and stays with the rest
 * of the word: "Anti|-harassment", "Co|-operation" (not "An|ti-harassment").
 * If that prefix has more than MAX_WORD_LETTERS letters, the syllable rule
 * applies to the prefix.
 */
class HeadingSplitter {

  /**
   * Longest first word, in letters, that is used whole in a multi-word title.
   */
  const MAX_WORD_LETTERS = 8;

  /**
   * Words with at least this many letters are "long" for balance purposes.
   */
  const LONG_WORD_LETTERS = 10;

  /**
   * The start of a long word is extended if it has this many letters or fewer.
   */
  const SHORT_START_LETTERS = 2;

  /**
   * Words whose start is chosen by hand: lower-case word => letters in the start.
   *
   * For words where the rules give a split that looks wrong. Applies whenever
   * the word opens a heading, or is the prefix before a hyphen.
   */
  const START_OVERRIDES = [
    'registration' => 3,
  ];

  /**
   * Lower-case vowels, including common accented ones and y.
   */
  const VOWELS = 'aeiouyàáâãäåæèéêëìíîïòóôõöøùúûüœ';

  /**
   * Digraphs that stay with the letters that follow them.
   */
  const LEADING_DIGRAPHS = ['sh', 'ch', 'th', 'ph', 'wh'];

  /**
   * Digraphs that stay with the letters that precede them.
   */
  const TRAILING_DIGRAPHS = ['ck', 'ng'];

  /**
   * Splits a heading into its highlighted start and the remainder.
   *
   * @param string $text
   *   Plain-text heading.
   *
   * @return array{0: string, 1: string}
   *   The start, then the rest. The two always join back into the original
   *   text, and the start is empty only when the text is empty or blank.
   */
  public function split(string $text): array {
    // Keep any leading whitespace out of the highlighted part.
    $text = ltrim($text);
    if ($text === '') {
      return ['', ''];
    }

    if (preg_match('/^(\S+)(.*)$/su', $text, $matches) !== 1) {
      return [$text, ''];
    }
    [, $first_word, $remainder] = $matches;

    // A word on its own, or followed only by a number, as in "Member 1".
    $is_single_word = preg_match('/^\s*\d*\s*$/u', $remainder) === 1;
    if ($is_single_word
      || $this->letterCount($first_word) > self::MAX_WORD_LETTERS
      || $this->startOverride($first_word) !== NULL) {
      [$lead, $tail] = $this->splitSyllable($first_word);
      $rest = $tail . $remainder;
    }
    else {
      [$lead, $rest] = [$first_word, $remainder];
    }

    // Sentence punctuation after the start stays out of it: "Privacy" then
    // ", Photographs", not "Privacy," then " Photographs".
    if (preg_match('/^(.*[\p{L}\p{N}])([,.;:!?…]+)$/su', $lead, $trailing) === 1) {
      [, $lead, $punctuation] = $trailing;
      $rest = $punctuation . $rest;
    }
    return [$lead, $rest];
  }

  /**
   * Splits a single word after its first (approximate) syllable.
   *
   * @return array{0: string, 1: string}
   */
  protected function splitSyllable(string $word, bool $extend = TRUE): array {
    // A start chosen by hand.
    $override = $this->startOverride($word);
    if ($override !== NULL && $override < mb_strlen($word)) {
      return [mb_substr($word, 0, $override), mb_substr($word, $override)];
    }

    // A hyphen after a prefix is a natural break: "Anti-harassment".
    if (preg_match('/^(\p{L}+)-(?=\p{L})/u', $word, $prefix) === 1) {
      $length = mb_strlen($prefix[1]);
      if ($length <= self::MAX_WORD_LETTERS) {
        return [$prefix[1], mb_substr($word, $length)];
      }
      [$lead, $tail] = $this->splitSyllable($prefix[1]);
      return [$lead, $tail . mb_substr($word, $length)];
    }

    $vowel = '[' . self::VOWELS . ']';
    $flags = 'ui';

    // Everything up to the end of the first vowel group.
    if (preg_match('/^[^' . self::VOWELS . ']*' . $vowel . '+/' . $flags, $word, $head) !== 1) {
      return [$word, ''];
    }
    $lead = $head[0];
    $rest = mb_substr($word, mb_strlen($lead));

    // Consonants up to the next vowel group; that group must exist and must
    // not just be a final silent "e".
    if (preg_match('/^([^' . self::VOWELS . ']+)(' . $vowel . '.*)$/' . $flags, $rest, $parts) !== 1
      || preg_match('/^e\P{L}*$/iu', $parts[2]) === 1) {
      return [$word, ''];
    }
    $consonants = $parts[1];
    $after = $parts[2];

    if (mb_strlen($consonants) === 1) {
      $take = 0;
    }
    else {
      $pair = mb_strtolower(mb_substr($consonants, 0, 2));
      $take = in_array($pair, self::LEADING_DIGRAPHS, TRUE) ? 0 : (in_array($pair, self::TRAILING_DIGRAPHS, TRUE) ? 2 : 1);
    }
    $lead .= mb_substr($consonants, 0, $take);
    $tail = mb_substr($consonants, $take) . $after;

    // A long word with a very short start: take one more syllable.
    if ($extend
      && $this->letterCount($word) >= static::LONG_WORD_LETTERS
      && $this->letterCount($lead) <= static::SHORT_START_LETTERS) {
      [$more, $after_more] = $this->splitSyllable($tail, FALSE);
      if ($after_more !== '') {
        return [$lead . $more, $after_more];
      }
    }
    return [$lead, $tail];
  }

  /**
   * Returns the hand-chosen start length for a word, or NULL if it has none.
   */
  protected function startOverride(string $word): ?int {
    $key = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $word));
    return self::START_OVERRIDES[$key] ?? NULL;
  }

  /**
   * Counts letters in a word, ignoring apostrophes, hyphens and digits.
   */
  protected function letterCount(string $word): int {
    return preg_match_all('/\p{L}/u', $word);
  }

}
