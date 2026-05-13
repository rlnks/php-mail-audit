<?php

namespace MailAudit\Detection;

/**
 * Detects 1×1 pixel images — typically used for open-tracking.
 * Fires as informational only (weight=0 in the rule JSON) so no score is deducted.
 *
 * A pixel is considered 1×1 when both width and height resolve to 1,
 * whether declared as HTML attributes (width="1") or inline CSS (width:1px).
 */
class HtmlTrackingPixelDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $locations = [];

        foreach ($matches[0] as [$imgTag, $offset]) {
            if ($this->extractDimension($imgTag, 'width')  === 1 &&
                $this->extractDimension($imgTag, 'height') === 1) {
                $locations[] = $this->buildLocation($html, $offset, strlen($imgTag));
            }
        }

        return $locations;
    }

    private function extractDimension(string $imgTag, string $attr): ?int
    {
        // HTML attribute: width="1", width='1', width=1
        if (preg_match('/\b' . $attr . '\s*=\s*["\']?(\d+)(?:px)?["\']?/i', $imgTag, $m)) {
            return (int) $m[1];
        }

        // Inline style: style="...width:1px..." or style="...width:1..."
        if (preg_match('/\bstyle\s*=\s*["\'][^"\']*\b' . $attr . '\s*:\s*(\d+)(?:px)?/i', $imgTag, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
