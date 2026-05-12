<?php

namespace MailAudit\Detection;

/**
 * Fires when a "trigger" pattern is present but an expected "fallback" pattern is absent.
 *
 * Use this to flag bad usage of a feature rather than its mere presence.
 * Example: @import detected (trigger) but no inline font-family fallback found (fallback absent) → bad.
 */
class CorrelationDetector extends AbstractDetector
{
    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $triggerConfig  = $detection['trigger']  ?? null;
        $fallbackConfig = $detection['fallback'] ?? null;

        if (!$triggerConfig || !isset($triggerConfig['type'])) {
            return [];
        }

        $triggerLocations = DetectorFactory::make($triggerConfig['type'])
            ->findMatches($html, $triggerConfig);

        if (empty($triggerLocations)) {
            return [];
        }

        if ($fallbackConfig && isset($fallbackConfig['type'])) {
            $fallbackLocations = DetectorFactory::make($fallbackConfig['type'])
                ->findMatches($html, $fallbackConfig);

            if (!empty($fallbackLocations)) {
                return [];
            }
        }

        return $triggerLocations;
    }
}
