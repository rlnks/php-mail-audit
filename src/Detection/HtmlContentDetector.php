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
        $isRegex   = $detection['regex'] ?? false;

        foreach ($detection['patterns'] ?? [] as $pattern) {
            if ($isRegex) {
                $safe = str_replace('~', '\\~', $pattern);
                if (preg_match_all('~' . $safe . '~isu', $html, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [$match, $offset]) {
                        $locations[] = $this->buildLocation($html, $offset, strlen($match));
                    }
                }
            } else {
                $offset = 0;
                while (($pos = strpos($html, $pattern, $offset)) !== false) {
                    $locations[] = $this->buildLocation($html, $pos, strlen($pattern));
                    $offset = $pos + 1;
                }
            }
        }

        return $locations;
    }
}
