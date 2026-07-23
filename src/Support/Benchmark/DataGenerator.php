<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Faker\Factory;
use Symfony\Component\String\UnicodeString;

class DataGenerator
{
    private array $seedPosts = [];

    private array $searchQueries = [];

    public function loadSeed(string $seedPath): void
    {
        if (! file_exists($seedPath)) {
            return;
        }

        $data = json_decode(file_get_contents($seedPath), true);

        $this->seedPosts = $data['posts'] ?? [];
    }

    public function generate(int $totalDocs, ?string $seedPath = null): array
    {
        if ($seedPath !== null) {
            $this->loadSeed($seedPath);
        }

        $posts = array_slice($this->seedPosts, 0, $totalDocs);
        $count = count($posts);

        if ($count < $totalDocs) {
            $locales = ['fr_FR', 'ar_SA', 'ru_RU', 'de_DE', 'es_ES', 'ja_JP', 'he_IL'];
            $fakerLocales = [];

            for ($i = $count; $i < $totalDocs; $i++) {
                $locale = $locales[array_rand($locales)];

                if (! isset($fakerLocales[$locale])) {
                    try {
                        $fakerLocales[$locale] = Factory::create($locale);
                    } catch (\Exception) {
                        $fakerLocales[$locale] = Factory::create();
                    }
                }

                $faker = $fakerLocales[$locale];
                $localeLabel = explode('_', $locale)[0];

                $posts[] = [
                    'title' => $faker->realText(60),
                    'body' => $faker->realText(600),
                    'author' => $localeLabel . '_author_' . $i,
                    'language' => $localeLabel,
                ];
            }
        }

        return $posts;
    }

    public function buildSearchQueries(array $posts, int $queryCount = 20): array
    {
        $queries = [
            'exact' => [],
            'typo' => [],
            'accent' => [],
            'nonexistent' => ['xyznonexistent123', 'qwertyuioplkjhgfdsa', 'zzzzzzzzzzzzzz', 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'],
        ];

        $typoMap = [
            'laravel' => 'laravil',
            'framework' => 'framwork',
            'software' => 'softwar',
            'programming' => 'progamming',
            'development' => 'develpment',
            'algorithm' => 'algoritm',
            'function' => 'funciton',
            'variable' => 'varible',
            'syntax' => 'synthax',
            'compiler' => 'compilor',
        ];

        $accentTests = [];

        // First pass: extract accent-bearing words from non-English posts
        foreach ($posts as $post) {
            if (count($accentTests) >= 5) {
                break;
            }

            $lang = $post['language'] ?? 'en';
            if ($lang === 'en') {
                continue;
            }

            $body = ($post['body'] ?? '') . ' ' . ($post['title'] ?? '');
            $accentWords = preg_split('/[\s,;:.!?()]+/', $body);
            foreach ($accentWords as $word) {
                $word = trim($word);
                if (preg_match('/[^a-zA-Z\x{00C0}-\x{024F}]/u', $word)) {
                    continue;
                }
                $ascii = (new UnicodeString($word))->ascii();
                if (mb_strlen($word) >= 4 && $word !== (string) $ascii && (string) $ascii !== '') {
                    $accentTests[$word] = (string) $ascii;
                    break;
                }
            }
        }

        $terms = [];

        // Second pass: extract search terms from titles
        foreach ($posts as $post) {
            $words = preg_split('/[\s,;:.!?()]+/', $post['title'] ?? '');
            foreach ($words as $w) {
                $w = trim(preg_replace('/[^a-zA-Z\x{00C0}-\x{024F}]+/u', '', $w));
                if (mb_strlen($w) >= 4 && ! in_array(mb_strtolower($w), $terms, true)) {
                    $terms[] = mb_strtolower($w);
                }
                if (count($terms) >= $queryCount) {
                    break 2;
                }
            }
        }

        $queries['exact'] = array_slice($terms, 0, $queryCount);

        foreach ($typoMap as $correct => $typo) {
            if (in_array($correct, $queries['exact'], true) || in_array($correct, $terms, true)) {
                $queries['typo'][] = ['query' => $typo, 'expected' => $correct];
            }
        }

        if (count($queries['typo']) < 5) {
            foreach ($typoMap as $expected => $query) {
                if (in_array($expected, $terms, true) || in_array($expected, $queries['exact'], true)) {
                    $queries['typo'][] = ['query' => $query, 'expected' => $expected];
                }
            }
        }

        $queries['accent'] = $accentTests;
        $this->searchQueries = $queries;

        return $queries;
    }
}
