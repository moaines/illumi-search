<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\SuggestRanker;
use Moaines\IllumiSearch\Tests\TestCase;

class SuggestRankerTest extends TestCase
{
    private SuggestRanker $ranker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranker = new SuggestRanker;
    }

    public function test_ranks_by_levenshtein_distance(): void
    {
        $rows = [
            (object) ['word' => 'laravil', 'ascii_word' => 'laravil'],
            (object) ['word' => 'laravel', 'ascii_word' => 'laravel'],
            (object) ['word' => 'larevel', 'ascii_word' => 'larevel'],
        ];

        $this->assertSame(['laravel', 'larevel'], $this->ranker->rank($rows, 'laravil', ['Latin'], 2));
    }

    public function test_excludes_words_beyond_max_distance(): void
    {
        $rows = [
            (object) ['word' => 'php', 'ascii_word' => 'php'],
            (object) ['word' => 'phpp', 'ascii_word' => 'phpp'],
            (object) ['word' => 'python', 'ascii_word' => 'python'],
        ];

        $this->assertSame(['php'], $this->ranker->rank($rows, 'phpp', ['Latin'], 2));
    }

    public function test_excludes_exact_match(): void
    {
        // Spellcheck never suggests the exact word that was typed.
        $rows = [
            (object) ['word' => 'laravel', 'ascii_word' => 'laravel'],
            (object) ['word' => 'larevel', 'ascii_word' => 'larevel'],
        ];

        $this->assertSame(['larevel'], $this->ranker->rank($rows, 'laravel', ['Latin'], 2));
    }

    public function test_prefers_same_script_over_ascii_proximity(): void
    {
        // "профессия" transliterates close to a Latin word, but a Latin query
        // must not rank it above a Latin candidate with the same distance.
        $rows = [
            (object) ['word' => 'laravel', 'ascii_word' => 'laravel'],
            (object) ['word' => 'профессия', 'ascii_word' => ''],
        ];

        $result = $this->ranker->rank($rows, 'larave', ['Latin'], 3);

        $this->assertContains('laravel', $result);
        $this->assertNotContains('профессия', $result);
    }

    public function test_ascii_transliterates_on_the_fly_when_not_stored(): void
    {
        // Cyrillic word, no ascii_word column — ranker must transliterate.
        $rows = [
            (object) ['word' => 'привет', 'ascii_word' => ''],
        ];

        // "privte" (transliteration + typo) is within distance of the query.
        $result = $this->ranker->rank($rows, 'privte', ['Cyrillic'], 2);
        $this->assertSame(['привет'], $result);
    }

    public function test_empty_and_single_char_words_are_ignored(): void
    {
        $rows = [
            (object) ['word' => '', 'ascii_word' => ''],
            (object) ['word' => 'a', 'ascii_word' => 'a'],
        ];

        $this->assertSame([], $this->ranker->rank($rows, 'a', ['Latin'], 2));
    }

    public function test_script_mismatch_penalty_is_shared_constant(): void
    {
        $this->assertSame(3, SuggestRanker::SCRIPT_MISMATCH_PENALTY);
    }
}
