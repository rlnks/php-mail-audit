<?php

namespace MailAudit\Detection;

/**
 * Matches arbitrary string patterns anywhere in the raw HTML.
 */
class HtmlContentDetector extends AbstractDetector
{
    public function findMatches(string $html, array $detection): array
    {
        $locations = [];

        foreach ($detection['patterns'] ?? [] as $pattern) {
            $offset = 0;
            while (($pos = strpos($html, $pattern, $offset)) !== false) {
                $locations[] = $this->buildLocation($html, $pos, strlen($pattern));
                $offset = $pos + 1;
            }
        }

        return $locations;
    }
}
