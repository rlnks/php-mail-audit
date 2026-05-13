<?php

namespace MailAudit\Detection;

/**
 * Detects font-family declarations where a multi-word font name is not quoted.
 *
 * Valid:   font-family: 'Open Sans', Arial, sans-serif
 * Valid:   font-family: "Times New Roman", serif
 * Invalid: font-family: Open Sans, Arial           ← "Open Sans" must be quoted
 * Invalid: font-family: Times New Roman, serif     ← "Times New Roman" must be quoted
 *
 * Single-word names and CSS generic families (serif, sans-serif, monospace, etc.)
 * never require quotes and are always skipped.
 */
class CssFontFamilyDetector extends AbstractDetector
{
    private const GENERIC_FAMILIES = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy',
        'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace',
        'ui-rounded', 'emoji', 'math', 'fangsong',
        'inherit', 'initial', 'unset', 'revert',
    ];

    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        // Capture font-family value up to the next ; } or end of attribute
        if (!preg_match_all(
            '/font-family\s*:\s*([^;}"\'<]+(?:[\'"][^\'"]*[\'"][^;}"\'<]*)*)/i',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $locations = [];
        $seen      = [];

        foreach ($matches[0] as $i => [$fullMatch, $offset]) {
            $value = rtrim(trim($matches[1][$i][0]), ',');
            // Deduplicate identical declarations (e.g. same inline style repeated across many tags)
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;

            if ($this->hasUnquotedMultiWordFont($value)) {
                $locations[] = $this->buildLocation($html, $offset, strlen($fullMatch));
            }
        }

        return $locations;
    }

    private function hasUnquotedMultiWordFont(string $value): bool
    {
        $value = preg_replace('/\s*!important\s*$/i', '', $value);

        foreach ($this->splitFontStack($value) as $font) {
            $font = trim($font);

            if ($font === '') {
                continue;
            }

            // Already quoted
            if (preg_match('/^["\']/', $font)) {
                continue;
            }

            // CSS variable — not a font name
            if (str_starts_with($font, 'var(')) {
                continue;
            }

            // Generic family keyword
            if (in_array(strtolower($font), self::GENERIC_FAMILIES, true)) {
                continue;
            }

            // Multi-word = contains a space → needs quotes
            if (str_contains($font, ' ')) {
                return true;
            }
        }

        return false;
    }

    /** Split a font-family value by commas, respecting quoted strings. */
    private function splitFontStack(string $value): array
    {
        $fonts     = [];
        $current   = '';
        $inQuote   = false;
        $quoteChar = '';
        $len       = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote   = true;
                $quoteChar = $char;
                $current  .= $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote  = false;
                $current .= $char;
            } elseif (!$inQuote && $char === ',') {
                $fonts[]  = $current;
                $current  = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $fonts[] = $current;
        }

        return $fonts;
    }
}
