<?php

namespace Moaines\IllumiSearch\Stopwords;

class StopwordFilter
{
    private static array $loaded = [];

    private const FALLBACK_LOCALE = 'en';

    public function filter(string $text, string $locale = self::FALLBACK_LOCALE): string
    {
        $stopwords = $this->load($locale);

        if (empty($stopwords)) {
            return $text;
        }

        $words = explode(' ', $text);
        $filtered = array_filter($words, fn ($w) => ! in_array($w, $stopwords, true));

        return implode(' ', $filtered);
    }

    public function load(string $locale): array
    {
        $lang = $this->resolveLanguage($locale);

        if (isset(static::$loaded[$lang])) {
            return static::$loaded[$lang];
        }

        $path = $this->filePath($lang);

        if ($path === null) {
            return static::$loaded[$lang] = [];
        }

        $words = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return static::$loaded[$lang] = $words !== false ? $words : [];
    }

    private function resolveLanguage(string $locale): string
    {
        $map = [
            'af' => 'afrikaans',    'sq' => 'albanian',
            'ar' => 'arabic',       'hy' => 'armenian',
            'az' => 'azerbaijani',  'eu' => 'basque',
            'bn' => 'bengali',      'bs' => 'bosnian',
            'bg' => 'bulgarian',    'ca' => 'catalan',
            'zh' => 'chinese',      'hr' => 'croatian',
            'cs' => 'czech',        'da' => 'danish',
            'nl' => 'dutch',        'en' => 'english',
            'et' => 'estonian',     'fi' => 'finnish',
            'fr' => 'french',       'gl' => 'galician',
            'de' => 'german',       'el' => 'greek',
            'gu' => 'gujarati',     'ha' => 'hausa',
            'he' => 'hebrew',       'hi' => 'hindi',
            'hu' => 'hungarian',    'is' => 'icelandic',
            'id' => 'indonesian',   'ga' => 'irish',
            'it' => 'italian',      'ja' => 'japanese',
            'ko' => 'korean',       'ku' => 'kurdish',
            'la' => 'latin',        'lv' => 'latvian',
            'lt' => 'lithuanian',   'mk' => 'macedonian',
            'ms' => 'malaysian',    'mt' => 'maltese',
            'no' => 'norwegian',    'fa' => 'persian',
            'pl' => 'polish',       'pt' => 'portuguese',
            'ro' => 'romanian',     'ru' => 'russian',
            'sr' => 'serbian',      'sk' => 'slovak',
            'sl' => 'slovenian',    'so' => 'somali',
            'es' => 'spanish',      'sw' => 'swahili',
            'sv' => 'swedish',      'tl' => 'tagalog',
            'tg' => 'tajik',        'tr' => 'turkish',
            'uk' => 'ukrainian',    'ur' => 'urdu',
            'uz' => 'uzbek',        'vi' => 'vietnamese',
            'cy' => 'welsh',        'xh' => 'xhosa',
            'yi' => 'yiddish',      'zu' => 'zulu',
        ];

        $lang = strtolower(substr($locale, 0, 2));

        return $map[$lang] ?? '';
    }

    private function filePath(string $lang): ?string
    {
        if ($lang === '') {
            return null;
        }

        $path = __DIR__ . '/../../resources/stopwords/' . $lang . '.txt';

        return file_exists($path) ? $path : null;
    }
}
