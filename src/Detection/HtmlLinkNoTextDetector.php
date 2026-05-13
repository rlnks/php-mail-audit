<?php

namespace MailAudit\Detection;

/**
 * Fires when an <a> tag is completely empty — no text and no child elements.
 * Links with images (even with alt="") are not flagged here; use empty-alt-img for that.
 */
class HtmlLinkNoTextDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        if (!preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $locations = [];

        foreach ($matches[0] as $i => [$fullMatch, $offset]) {
            $content = $matches[1][$i][0];

            // Has visible text → accessible
            if (trim(strip_tags($content)) !== '') {
                continue;
            }

            // Has any child element (img, span, etc.) → not our concern
            if (preg_match('/<[a-z]/i', $content)) {
                continue;
            }

            $locations[] = $this->buildLocation($html, $offset, strlen($fullMatch));
        }

        return $locations;
    }
}
