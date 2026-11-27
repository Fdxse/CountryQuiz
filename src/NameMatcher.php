<?php

declare(strict_types=1);

namespace CountryQuiz;

/**
 * Compares typed country names in a forgiving way:
 * case, accents, punctuation and spacing are ignored ("elfenbenskusten" == "Elfenbenskusten",
 * "cote divoire" == "Côte d'Ivoire").
 */
final class NameMatcher
{
    /** Accented characters mapped to plain ASCII (avoids depending on the intl extension). */
    private const ACCENT_MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
        'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ž' => 'z', 'ß' => 'ss', 'ğ' => 'g',
        'ı' => 'i', 'ş' => 's', 'ł' => 'l', 'ő' => 'o', 'ű' => 'u', 'ř' => 'r', 'ě' => 'e',
    ];

    /** Words that are often left out when typing ("The Bahamas" -> "Bahamas"). */
    private const IGNORED_WORDS = ['the', 'of'];

    public static function normalize(string $name): string
    {
        $lowercase = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $plain = strtr($lowercase, self::ACCENT_MAP);
        // Treat "&" as "and"/"och" so both spellings work.
        $plain = str_replace('&', ' and ', $plain);
        // Remove apostrophes completely ("d'Ivoire" -> "divoire"), turn other punctuation into spaces.
        $plain = str_replace(["'", '’'], '', $plain);
        $plain = (string) preg_replace('/[^a-z0-9]+/', ' ', $plain);

        $words = array_filter(
            explode(' ', $plain),
            static fn (string $word): bool => $word !== '' && !in_array($word, self::IGNORED_WORDS, true)
        );
        return implode('', $words);
    }

    /**
     * Every normalized name that should be accepted for a country (English, Swedish and aliases).
     *
     * @param array<string, mixed> $country
     * @return list<string>
     */
    public static function acceptedNames(array $country): array
    {
        $names = array_merge([$country['nameEn'], $country['nameSv']], $country['aliases']);
        return array_values(array_unique(array_map(self::normalize(...), $names)));
    }

    /**
     * @param array<string, mixed> $country
     */
    public static function matches(string $typedName, array $country): bool
    {
        $normalizedTypedName = self::normalize($typedName);
        return $normalizedTypedName !== '' && in_array($normalizedTypedName, self::acceptedNames($country), true);
    }
}
