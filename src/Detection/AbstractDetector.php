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
}
