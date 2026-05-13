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

        // Step 1: find all opening tags — [^>]* handles any number of spaces between attributes
        if (!preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*>/si', $html, $tagMatches, PREG_OFFSET_CAPTURE)) {
            return $locations;
        }

        foreach ($tagMatches[0] as [$tagHtml, $offset]) {
            // Step 2: extract inline style value
            // \s before "style" ensures we match the attribute, not e.g. data-style
            if (!preg_match('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tagHtml, $sm)) {
                continue;
            }
            $styleValue = $sm[1] !== '' ? $sm[1] : ($sm[2] !== '' ? $sm[2] : ($sm[3] ?? ''));

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
