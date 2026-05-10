<?php

namespace MailAudit\Detection;

/**
 * Matches CSS patterns inside inline style attributes and <style> blocks.
 * Supports optional regex mode via "regex": true in the detection config.
 */
class CssPropertyDetector extends AbstractDetector
{
    public function findMatches(string $html, array $detection): array
    {
        $locations = [];
        $isRegex   = $detection['regex'] ?? false;

        foreach ($detection['patterns'] ?? [] as $pattern) {
            if ($isRegex) {
                if (preg_match_all('/' . $pattern . '/i', $html, $m, PREG_OFFSET_CAPTURE)) {
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
