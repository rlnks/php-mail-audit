<?php

namespace MailAudit\Detection;

class HtmlTagWithStyleDetector extends AbstractDetector
{
    /**
     * Finds <tag> elements whose inline style attribute contains any of the given css_patterns.
     *
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $tag         = $detection['tag'] ?? 'div';
        $cssPatterns = $detection['css_patterns'] ?? [];
        $useRegex    = $detection['regex'] ?? false;
        $locations   = [];

        $regex = '/<' . preg_quote($tag, '/') . '(?:\s[^>]*)?\s+style\s*=\s*(?:"([^"]*?)"|\'([^\']*?)\'|([^\s>]+))[^>]*>/si';

        if (!preg_match_all($regex, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $locations;
        }

        foreach ($matches[0] as $i => [$tagHtml, $offset]) {
            $styleValue = $matches[1][$i][0] !== ''
                ? $matches[1][$i][0]
                : ($matches[2][$i][0] !== '' ? $matches[2][$i][0] : $matches[3][$i][0]);

            foreach ($cssPatterns as $pattern) {
                $hit = $useRegex
                    ? (bool) preg_match('/' . $pattern . '/i', $styleValue)
                    : stripos($styleValue, $pattern) !== false;

                if ($hit) {
                    $locations[] = $this->buildLocation($html, $offset, strlen($tagHtml));
                    break;
                }
            }
        }

        return $locations;
    }
}
