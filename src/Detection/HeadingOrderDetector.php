<?php

namespace MailAudit\Detection;

/**
 * Fires when heading levels are skipped (e.g. <h1> followed directly by <h3>).
 * Only the out-of-order heading is flagged, not the one before it.
 */
class HeadingOrderDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        if (!preg_match_all('/<(h[1-6])\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $locations = [];
        $prevLevel = 0;

        foreach ($matches[1] as $i => [$tagName]) {
            $level  = (int) $tagName[1]; // 'h1' → 1
            $offset = $matches[0][$i][1];
            $length = strlen($matches[0][$i][0]);

            if ($prevLevel > 0 && $level > $prevLevel + 1) {
                $locations[] = $this->buildLocation($html, $offset, $length);
            }

            $prevLevel = $level;
        }

        return $locations;
    }
}
