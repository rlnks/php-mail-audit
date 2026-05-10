<?php

namespace MailAudit\Analysis;

class ScoringEngine
{
    public function calculate(array $triggeredRules): int
    {
        $deduction = 0.0;

        foreach ($triggeredRules as $rule) {
            $multiplier = match ($rule['severity'] ?? 'info') {
                'error'   => 1.0,
                'warning' => 0.6,
                'info'    => 0.3,
                default   => 0.5,
            };
            $deduction += ($rule['weight'] ?? 0) * $multiplier;
        }

        return max(0, (int) round(100 - $deduction));
    }
}
