<?php

namespace MailAudit\Detection;

/**
 * Fires when an <a> tag contains no accessible text:
 *  - no text nodes, AND
 *  - no <img> with a non-empty alt attribute.
 *
 * An image-only link is accessible when the image has descriptive alt text.
 * An image-only link with alt="" (or no alt) is inaccessible.
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

            // Has visible text — accessible
            if (trim(strip_tags($content)) !== '') {
                continue;
            }

            // No text: check whether any contained <img> provides alt text
            if ($this->hasImageWithAlt($content)) {
                continue;
            }

            $locations[] = $this->buildLocation($html, $offset, strlen($fullMatch));
        }

        return $locations;
    }

    private function hasImageWithAlt(string $content): bool
    {
        if (!preg_match_all('/<img\b[^>]*>/i', $content, $imgs)) {
            return false;
        }

        foreach ($imgs[0] as $imgTag) {
            // alt="something" or alt='something' (non-empty)
            if (preg_match('/\balt\s*=\s*"([^"]+)"/i', $imgTag) ||
                preg_match("/\\balt\\s*=\\s*'([^']+)'/i", $imgTag)) {
                return true;
            }
        }

        return false;
    }
}
