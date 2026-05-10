<?php

namespace MailAudit\Detection;

interface DetectorInterface
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array;

    public function matches(string $html, array $detection): bool;
}
