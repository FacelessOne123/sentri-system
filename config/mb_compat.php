<?php
/**
 * SenTri mbstring Compatibility Layer
 * Loaded from config/headers.php so every entrypoint gets it.
 *
 * The portal renderers (lgu.php, responder.php, barangay.php, admin.php)
 * call mb_strimwidth() to truncate report titles. On hosts where the
 * mbstring extension is compiled but not enabled, PHP fatals mid-render
 * and the reports table emits only "#<id>" and breaks. These fallbacks
 * keep the pages rendering with sane ASCII/UTF-8-safe truncation.
 */

if (!function_exists('mb_strimwidth')) {
    /**
     * Width-aware truncation fallback (1 byte = 1 "column" of width).
     */
    function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = '', ?string $encoding = null): string {
        $len = strlen($string);
        if (($start + $width) >= $len) {
            return substr($string, $start);
        }
        $marker_len = strlen($trim_marker);
        $take = max(0, $width - $marker_len);
        return $trim_marker . substr($string, $start, $take);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}
