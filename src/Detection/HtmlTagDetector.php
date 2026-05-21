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
        $locations     = [];
        $excludeInside = $detection['exclude_inside'] ?? [];
        $skipTextOnly  = $detection['skip_text_only'] ?? false;

        $excludeRanges = [];
        foreach ($excludeInside as $parentTag) {
            foreach ($this->getTagRanges($html, $parentTag) as $range) {
                $excludeRanges[] = $range;
            }
        }

        foreach ($detection['patterns'] ?? [] as $tag) {
            $tag   = trim($tag, '<');
            $regex = '/<' . preg_quote($tag, '/') . '[^>]*>/si';

            if (preg_match_all($regex, $html, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    if ($excludeRanges && $this->isOffsetInRanges($offset, $excludeRanges)) {
                        continue;
                    }
                    if ($skipTextOnly && $this->isDivContentTextOnly($html, $offset)) {
                        continue;
                    }
                    $locations[] = $this->buildLocation($html, $offset, strlen($match));
                }
            }
        }

        return $locations;
    }
}
