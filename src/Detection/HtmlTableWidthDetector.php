<?php

namespace MailAudit\Detection;

/**
 * Fires when a <table> has a fixed pixel width exceeding max_width.
 * Percentage widths (width="100%") and tables with no fixed width are ignored —
 * this correctly skips the common full-width wrapper pattern used to override body styles.
 */
class HtmlTableWidthDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $maxWidth = $detection['max_width'] ?? 600;

        if (!preg_match_all('/<table\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $locations = [];

        foreach ($matches[0] as [$tagHtml, $offset]) {
            $width = $this->getFixedPixelWidth($tagHtml);
            if ($width !== null && $width > $maxWidth) {
                $locations[] = $this->buildLocation($html, $offset, strlen($tagHtml));
            }
        }

        return $locations;
    }

    private function getFixedPixelWidth(string $tagHtml): ?int
    {
        // width="NNN" attribute — only pure integers (not "100%")
        if (preg_match('/\bwidth\s*=\s*["\']?(\d+)["\']?/i', $tagHtml, $m)) {
            return (int) $m[1];
        }

        // Inline style: width:NNNpx
        if (preg_match('/\bwidth\s*:\s*(\d+)px/i', $tagHtml, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
