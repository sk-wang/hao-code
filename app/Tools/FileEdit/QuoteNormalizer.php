<?php

namespace App\Tools\FileEdit;

/**
 * Handles curly quote normalization and preservation, matching claude-code's
 * FileEditTool/utils.ts quote handling.
 *
 * The model often outputs straight quotes, but the file may use curly (smart)
 * quotes. This class normalizes both for matching, then preserves the file's
 * original typography in the replacement.
 */
class QuoteNormalizer
{
    // Curly quote characters
    private const LEFT_SINGLE = "\u{2018}";   // '
    private const RIGHT_SINGLE = "\u{2019}";  // '
    private const LEFT_DOUBLE = "\u{201C}";   // "
    private const RIGHT_DOUBLE = "\u{201D}";  // "

    /**
     * Normalize curly quotes to straight quotes for comparison.
     */
    public static function normalize(string $str): string
    {
        return str_replace(
            [self::LEFT_SINGLE, self::RIGHT_SINGLE, self::LEFT_DOUBLE, self::RIGHT_DOUBLE],
            ["'", "'", '"', '"'],
            $str,
        );
    }

    /**
     * Find the actual string in file content, with curly quote fallback.
     *
     * 1. Try exact match first
     * 2. If no match, normalize both and try again
     * 3. Return the actual substring from the original file
     */
    public static function findActualString(string $fileContent, string $searchString): ?string
    {
        // Exact match
        if (str_contains($fileContent, $searchString)) {
            return $searchString;
        }

        // Normalized fallback
        $normalizedSearch = self::normalize($searchString);
        $normalizedFile = self::normalize($fileContent);

        $pos = strpos($normalizedFile, $normalizedSearch);
        if ($pos === false) {
            return null;
        }

        // Return actual substring from original file (preserves curly quotes)
        return mb_substr($fileContent, $pos, mb_strlen($searchString));
    }

    /**
     * Count occurrences in file, with curly quote normalization.
     */
    public static function countOccurrences(string $fileContent, string $searchString): int
    {
        // Try exact first
        $exactCount = substr_count($fileContent, $searchString);
        if ($exactCount > 0) {
            return $exactCount;
        }

        // Normalized fallback
        $normalizedSearch = self::normalize($searchString);
        $normalizedFile = self::normalize($fileContent);

        return substr_count($normalizedFile, $normalizedSearch);
    }

    /**
     * Preserve the file's curly quote style in the replacement string.
     *
     * If the old_string was matched via normalization (i.e., the file uses curly
     * quotes but the model sent straight quotes), apply the same curly style
     * to the new_string so typography stays consistent.
     */
    public static function preserveQuoteStyle(string $oldString, string $actualOldString, string $newString): string
    {
        if ($oldString === $actualOldString) {
            return $newString;
        }

        $hasDouble = str_contains($actualOldString, self::LEFT_DOUBLE)
            || str_contains($actualOldString, self::RIGHT_DOUBLE);
        $hasSingle = str_contains($actualOldString, self::LEFT_SINGLE)
            || str_contains($actualOldString, self::RIGHT_SINGLE);

        if (!$hasDouble && !$hasSingle) {
            return $newString;
        }

        $result = $newString;
        if ($hasDouble) {
            $result = self::applyCurlyDoubleQuotes($result);
        }
        if ($hasSingle) {
            $result = self::applyCurlySingleQuotes($result);
        }

        return $result;
    }

    private static function isOpeningContext(array $chars, int $index): bool
    {
        if ($index === 0) {
            return true;
        }
        $prev = $chars[$index - 1];

        return in_array($prev, [' ', "\t", "\n", "\r", '(', '[', '{', "\u{2014}", "\u{2013}"], true);
    }

    private static function applyCurlyDoubleQuotes(string $str): string
    {
        $chars = mb_str_split($str);
        $result = [];

        foreach ($chars as $i => $ch) {
            if ($ch === '"') {
                $result[] = self::isOpeningContext($chars, $i)
                    ? self::LEFT_DOUBLE
                    : self::RIGHT_DOUBLE;
            } else {
                $result[] = $ch;
            }
        }

        return implode('', $result);
    }

    private static function applyCurlySingleQuotes(string $str): string
    {
        $chars = mb_str_split($str);
        $result = [];

        foreach ($chars as $i => $ch) {
            if ($ch === "'") {
                $prev = $i > 0 ? $chars[$i - 1] : null;
                $next = $i < count($chars) - 1 ? $chars[$i + 1] : null;
                $prevIsLetter = $prev !== null && preg_match('/\pL/u', $prev);
                $nextIsLetter = $next !== null && preg_match('/\pL/u', $next);

                if ($prevIsLetter && $nextIsLetter) {
                    // Apostrophe in contraction (don't, it's)
                    $result[] = self::RIGHT_SINGLE;
                } else {
                    $result[] = self::isOpeningContext($chars, $i)
                        ? self::LEFT_SINGLE
                        : self::RIGHT_SINGLE;
                }
            } else {
                $result[] = $ch;
            }
        }

        return implode('', $result);
    }
}
