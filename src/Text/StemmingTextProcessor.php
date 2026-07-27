<?php

namespace Moaines\IllumiSearch\Text;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class StemmingTextProcessor extends UnicodeTextProcessor implements TextProcessor
{
    /** @var array<string, Stemmer> */
    protected static array $stemmers = [];

    public function process(string $text, string $locale = 'en'): string
    {
        $text = parent::process($text, $locale);

        $language = \Locale::getPrimaryLanguage($locale);
        if ($language === null) {
            return $text;
        }

        $stemmer = $this->resolveStemmer($language);
        if ($stemmer === null) {
            return $text;
        }

        $words = explode(' ', $text);

        return implode(' ', array_map(fn ($word) => $stemmer->stem($word), $words));
    }

    private function resolveStemmer(string $language): ?Stemmer
    {
        if (isset(static::$stemmers[$language])) {
            return static::$stemmers[$language];
        }

        try {
            $stemmer = StemmerFactory::create($language);
            static::$stemmers[$language] = $stemmer;

            return $stemmer;
        } catch (NotFoundException) {
            return null;
        }
    }
}
