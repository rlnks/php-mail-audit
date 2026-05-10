<?php

namespace MailAudit\Detection;

/**
 * Searches for patterns exclusively inside <style> block content.
 * Tracks absolute positions in the original HTML for each match.
 *
 * Rule JSON shape:
 *   "detection": { "type": "style_block", "patterns": ["@media", ":hover"] }
 *   "detection": { "type": "style_block", "regex": true, "patterns": ["\\.([a-zA-Z_-][\\w-]*)\\s*[{,:\\[]"] }
 */
class StyleBlockDetector extends AbstractDetector
{
    public function findMatches(string $html, array $detection): array
    {
        $locations = [];
        $useRegex  = $detection['regex'] ?? false;
        $patterns  = $detection['patterns'] ?? [];

        if (!preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $styleMatches, PREG_OFFSET_CAPTURE)) {
            return $locations;
        }

        foreach ($styleMatches[1] as [$blockContent, $blockOffset]) {
            foreach ($patterns as $pattern) {
                if ($useRegex) {
                    if (preg_match_all('/' . $pattern . '/i', $blockContent, $m, PREG_OFFSET_CAPTURE)) {
                        foreach ($m[0] as [$match, $relOffset]) {
                            $absOffset   = $blockOffset + $relOffset;
                            $locations[] = $this->buildLocation($html, $absOffset, strlen($match));
                        }
                    }
                } else {
                    $relOffset = 0;
                    while (($pos = strpos($blockContent, $pattern, $relOffset)) !== false) {
                        $absOffset   = $blockOffset + $pos;
                        $locations[] = $this->buildLocation($html, $absOffset, strlen($pattern));
                        $relOffset   = $pos + 1;
                    }
                }
            }
        }

        return $locations;
    }
}
