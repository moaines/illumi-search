<?php

namespace Moaines\IllumiSearch\Text;

class ArabicTextProcessor
{
    private const TASHKEEL = ['ٌ', 'ُ', 'ً', 'َ', 'ٍ', 'ِ', 'ّ', 'ْ'];
    private const HAMZA = ['إ', 'آ', 'ئ', 'ؤ', 'ء'];
    private const PREFIXES = ['وال', 'ولل', 'بال', 'كال', 'ال', 'لل'];
    private const SUFFIXES = ['تمل', 'همل', 'تان', 'تين', 'كمل', 'ات', 'ون', 'ين', 'ان', 'تن', 'كم', 'هن', 'نا', 'يا', 'ها', 'تم', 'كن', 'ني', 'وا', 'ما', 'هم', 'ة', 'ه', 'ي', 'ك'];

    public function process(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text));
        $result = [];

        foreach ($words as $word) {
            if (preg_match('/\p{Arabic}/u', $word)) {
                $word = $this->normalize($word);
                $word = $this->removePrefix($word);
                $word = $this->removeSuffix($word);
                $word = $this->reduceDoubles($word);
            }
            $result[] = $word;
        }

        return implode(' ', $result);
    }

    private function normalize(string $word): string
    {
        $word = str_replace(self::TASHKEEL, '', $word);
        $word = str_replace(self::HAMZA, 'ا', $word);
        $word = str_replace(['ة', 'ى'], ['ه', 'ي'], $word);

        return $word;
    }

    private function removePrefix(string $word): string
    {
        foreach (self::PREFIXES as $pre) {
            $len = mb_strlen($pre);
            if (mb_strlen($word) > $len + 2 && mb_substr($word, 0, $len) === $pre) {
                return mb_substr($word, $len);
            }
        }

        return $word;
    }

    private function removeSuffix(string $word): string
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::SUFFIXES as $suf) {
                $len = mb_strlen($suf);
                if (mb_strlen($word) > $len + 2 && mb_substr($word, -$len) === $suf) {
                    $word = mb_substr($word, 0, -$len);
                    $changed = true;
                    break; // restart from the longest suffix
                }
            }
        }

        return $word;
    }

    private function reduceDoubles(string $word): string
    {
        return preg_replace('/(\p{Arabic})\1+/u', '$1', $word);
    }
}
