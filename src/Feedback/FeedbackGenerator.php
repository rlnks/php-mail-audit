<?php

namespace MailAudit\Feedback;

class FeedbackGenerator
{
    /** @var string|list<string> */
    private string|array $locale;

    /** @param string|list<string> $locale */
    public function __construct(string|array $locale = 'en')
    {
        $this->locale = $locale;
    }

    public function generate(array $triggeredRules): array
    {
        return array_map(fn(array $rule) => [
            'id'               => $rule['id'],
            'severity'         => $rule['severity'],
            'weight'           => $rule['weight'],
            'message'          => $this->resolve($rule['message'] ?? []),
            'fix'              => $this->resolve($rule['fix'] ?? []),
            'affected_clients' => $rule['affected_clients'] ?? [],
            'tags'             => $rule['tags'] ?? [],
            'locations'        => $rule['locations'] ?? [],
        ], $triggeredRules);
    }

    public function generatePassed(array $passedRules): array
    {
        $result = [];
        foreach ($passedRules as $rule) {
            if (!isset($rule['success_message'])) {
                continue;
            }
            $result[] = [
                'id'      => $rule['id'],
                'severity' => $rule['severity'],
                'message' => $this->resolve($rule['success_message']),
                'tags'    => $rule['tags'] ?? [],
            ];
        }
        return $result;
    }

    /** @return string|array<string,string> */
    private function resolve(array $translations): string|array
    {
        if (is_string($this->locale)) {
            return $translations[$this->locale] ?? $translations['en'] ?? '';
        }

        $out = [];
        foreach ($this->locale as $lang) {
            $out[$lang] = $translations[$lang] ?? $translations['en'] ?? '';
        }
        return $out;
    }
}
