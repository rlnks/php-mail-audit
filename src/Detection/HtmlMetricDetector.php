<?php

namespace MailAudit\Detection;

/**
 * Fires when a measurable property of the HTML crosses a threshold.
 *
 * Supported metrics:
 *  - "size"             : byte length of the full HTML string (threshold in bytes)
 *  - "text_image_ratio" : text chars as a % of (text + image weight); fires when below threshold
 *
 * Optional "operator": "gt" (default — fires when value > threshold)
 *                      "lt"            — fires when value < threshold
 */
class HtmlMetricDetector extends AbstractDetector
{
    private const IMAGE_CHAR_WEIGHT = 300;

    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $metric    = $detection['metric']    ?? '';
        $threshold = $detection['threshold'] ?? 0;
        $operator  = $detection['operator']  ?? 'gt';

        $value = match ($metric) {
            'size'             => strlen($html),
            'text_image_ratio' => $this->textImageRatio($html),
            default            => 0,
        };

        $fires = match ($operator) {
            'lt'    => $threshold > 0 && $value < $threshold,
            default => $threshold > 0 && $value > $threshold,
        };

        if ($fires) {
            return [['line' => 1, 'column' => 1, 'offset_start' => 0, 'offset_end' => strlen($html)]];
        }

        return [];
    }

    /**
     * Returns text content as a percentage of (text + weighted image count).
     * Skips <style>, <script>, and <head> blocks — they are not readable content.
     * Returns 100 for fragments (no <body>) or image-free emails to avoid false positives.
     */
    private function textImageRatio(string $html): int
    {
        if (stripos($html, '<body') === false) {
            return 100;
        }

        $clean    = preg_replace('/<(style|script|head)\b[^>]*>.*?<\/\1>/is', '', $html);
        $imgCount = preg_match_all('/<img\b/i', $clean, $dummy);

        if ($imgCount === 0) {
            return 100;
        }

        $text    = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textLen = mb_strlen(preg_replace('/\s+/', '', $text));

        if ($textLen === 0) {
            return 0;
        }

        return (int) round($textLen / ($textLen + $imgCount * self::IMAGE_CHAR_WEIGHT) * 100);
    }
}
