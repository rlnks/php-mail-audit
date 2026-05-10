<?php

namespace MailAudit\Analysis;

use MailAudit\Detection\DetectorFactory;

class RuleEngine
{
    private array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function analyze(string $html): array
    {
        $triggered = [];

        foreach ($this->rules as $rule) {
            $type = $rule['detection']['type'] ?? null;
            if (!$type) {
                continue;
            }

            $detector  = DetectorFactory::make($type);
            $locations = $detector->findMatches($html, $rule['detection']);

            if (!empty($locations)) {
                $triggered[] = $rule + ['locations' => $locations];
            }
        }

        return $triggered;
    }
}
