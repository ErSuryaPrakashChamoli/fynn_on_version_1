<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Masking for CIBIL screens and scrubbing for anything the bureau says that
 * gets stored or logged. Listings show a PAN or mobile number only in part;
 * a provider message is kept only after PANs, phone numbers, emails and
 * URLs (signed PDF links included) are taken out of it.
 */
final class CibilMask
{
    /** ABCDE1234F → ABXXXXXX4F */
    public static function pan(?string $pan): string
    {
        $pan = Str::upper(trim((string) $pan));

        if (strlen($pan) < 5) {
            return $pan === '' ? '—' : str_repeat('X', strlen($pan));
        }

        return substr($pan, 0, 2).str_repeat('X', strlen($pan) - 4).substr($pan, -2);
    }

    /** 9876543210 → XXXXXX3210 */
    public static function mobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return strlen($digits) <= 4
            ? str_repeat('X', strlen($digits))
            : str_repeat('X', strlen($digits) - 4).substr($digits, -4);
    }

    /**
     * Provider text made safe to keep: identifiers and links removed,
     * whitespace collapsed, length capped.
     */
    public static function scrub(?string $text, int $limit = 200): ?string
    {
        if (blank($text)) {
            return null;
        }

        $text = preg_replace([
            '~\bhttps?://\S+~i',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            '/\b[A-Z]{5}[0-9]{4}[A-Z]\b/i',
            '/(?<!\d)(?:\+?91[\s-]?)?[6-9]\d{9}(?!\d)/',
            '/\bBearer\s+\S+/i',
        ], ['[link]', '[email]', '[PAN]', '[mobile]', '[credential]'], (string) $text);

        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        return $text === '' ? null : Str::limit($text, $limit);
    }
}
