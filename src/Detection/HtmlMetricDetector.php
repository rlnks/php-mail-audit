<?php

namespace MailAudit\Detection;

/**
 * Fires when a measurable property of the HTML exceeds a threshold.
 *
 * Supported metrics:
 *  - "size" : byte length of the full HTML string (threshold in bytes)
 */
class HtmlMetricDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $metric    = $detection['metric']    ?? '';
        $threshold = $detection['threshold'] ?? 0;

        $value = match ($metric) {
            'size'  => strlen($html),
            default => 0,
        };

        if ($threshold > 0 && $value > $threshold) {
            return [['line' => 1, 'column' => 1, 'offset_start' => 0, 'offset_end' => strlen($html)]];
        }

        return [];
    }
}
