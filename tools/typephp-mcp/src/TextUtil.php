<?php

final class TextUtil
{
    private const TRUNCATION_MARKER = "\n...[truncated]";

    /**
     * Clip text to a byte budget. The result never exceeds $maxBytes and never
     * ends (or starts, see utf8SafeSlice) inside a UTF-8 sequence, so the
     * output is always safe for json_encode.
     */
    public static function clip(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $budget = $maxBytes - strlen(self::TRUNCATION_MARKER);
        if ($budget < 1) {
            $budget = 1;
        }
        return self::utf8SafeSlice($text, 0, $budget) . self::TRUNCATION_MARKER;
    }

    /**
     * Cut a byte window that starts and ends on UTF-8 character boundaries.
     */
    public static function utf8SafeSlice(string $text, int $start, int $length): string
    {
        if ($start < 0) {
            $start = 0;
        }
        $slice = substr($text, $start, $length);
        // Drop leading continuation bytes of a character that started earlier.
        $slice = (string) preg_replace('/^[\x80-\xBF]+/', '', $slice);
        // Keep the longest prefix made of complete UTF-8 characters, which
        // drops any trailing partial sequence.
        $matched = preg_match(
            '/^(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3})*/',
            $slice,
            $matches
        );
        if ($matched !== 1 || !is_array($matches)) {
            return '';
        }
        return (string) $matches[0];
    }

    /**
     * Split a search query into lowercase terms. CJK text has no word
     * boundaries, so terms keep their punctuation and match by substring.
     *
     * @return array<int, string>
     */
    public static function terms(string $query): array
    {
        $parts = preg_split('/[\s,，、;；|]+/', $query);
        $terms = [];
        if (!is_array($parts)) {
            return $terms;
        }
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }
            $part = trim($part);
            if ($part !== '') {
                $terms[] = strtolower($part);
            }
        }
        return $terms;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }
}
