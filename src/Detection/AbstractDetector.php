<?php

namespace MailAudit\Detection;

abstract class AbstractDetector implements DetectorInterface
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    abstract public function findMatches(string $html, array $detection): array;

    public function matches(string $html, array $detection): bool
    {
        return !empty($this->findMatches($html, $detection));
    }

    /**
     * @return array{line: int, column: int, offset_start: int, offset_end: int}
     */
    protected function buildLocation(string $html, int $offsetStart, int $length): array
    {
        $before = substr($html, 0, $offsetStart);
        $line   = substr_count($before, "\n") + 1;
        $lastNl = strrpos($before, "\n");
        $column = $lastNl === false ? $offsetStart + 1 : $offsetStart - $lastNl;

        return [
            'line'         => $line,
            'column'       => $column,
            'offset_start' => $offsetStart,
            'offset_end'   => $offsetStart + $length,
        ];
    }

    /** @return list<array{int, int}> */
    protected function getTagRanges(string $html, string $tag): array
    {
        $pattern = '/<' . preg_quote($tag, '/') . '\b[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/si';
        if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $ranges = [];
        foreach ($matches[0] as [$match, $start]) {
            $ranges[] = [$start, $start + strlen($match)];
        }
        return $ranges;
    }

    /** @param list<array{int, int}> $ranges */
    protected function isOffsetInRanges(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }
        return false;
    }

    // A <div> whose content has no block-level children could validly be a <p> → it is text content.
    // If the div is text-only, the first </div> after the opening tag IS the closing tag (no nested divs possible).
    protected function isDivContentTextOnly(string $html, int $tagOffset): bool
    {
        $openEnd = strpos($html, '>', $tagOffset);
        if ($openEnd === false) {
            return false;
        }
        $closePos = stripos($html, '</div>', $openEnd + 1);
        if ($closePos === false) {
            return false;
        }
        $content = substr($html, $openEnd + 1, $closePos - $openEnd - 1);
        return !preg_match(
            '/<(div|p|table|thead|tbody|tfoot|tr|td|th|ul|ol|li|h[1-6]|blockquote|section|article|aside|header|footer|main|figure|figcaption|details|summary|dialog|form|fieldset|nav)\b/i',
            $content
        );
    }
}
