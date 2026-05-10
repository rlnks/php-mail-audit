<?php

namespace MailAudit\Detection;

/**
 * Fires when the specified HTML tag is present at least once in the document.
 * Uses regex to locate each occurrence and provide character positions.
 */
class HtmlTagDetector extends AbstractDetector
{
    public function findMatches(string $html, array $detection): array
    {
        $locations = [];

        foreach ($detection['patterns'] ?? [] as $tag) {
            $tag   = trim($tag, '<');
            $regex = '/<' . preg_quote($tag, '/') . '[^>]*>/si';

            if (preg_match_all($regex, $html, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    $locations[] = $this->buildLocation($html, $offset, strlen($match));
                }
            }
        }

        return $locations;
    }
}
