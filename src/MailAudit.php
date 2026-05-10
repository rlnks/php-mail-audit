<?php

namespace MailAudit;

use MailAudit\Analysis\RuleEngine;
use MailAudit\Analysis\ScoringEngine;
use MailAudit\Feedback\FeedbackGenerator;
use MailAudit\Loader\RuleLoader;

class MailAudit
{
    private RuleLoader $loader;
    private ScoringEngine $scoring;
    private FeedbackGenerator $feedback;
    /** @var string|list<string> */
    private string|array $locale;

    /** @param string|list<string> $locale */
    public function __construct(array $config = [], string|array $locale = 'en')
    {
        $this->locale   = $locale;
        $this->loader   = new RuleLoader($config);
        $this->scoring  = new ScoringEngine();
        $this->feedback = new FeedbackGenerator($locale);
    }

    public function analyze(string $html): array
    {
        $rules   = $this->loader->load();
        $engine  = new RuleEngine($rules);

        $triggered    = $engine->analyze($html);
        $triggeredIds = array_column($triggered, 'id');
        $passed       = array_values(array_filter($rules, fn($r) => !in_array($r['id'], $triggeredIds, true)));

        $score     = $this->scoring->calculate($triggered);
        $insights  = $this->feedback->generate($triggered);
        $successes = $this->feedback->generatePassed($passed);

        return [
            'score'    => $score,
            'insights' => $insights,
            'passed'   => $successes,
            'summary'  => [
                'total_rules_checked'   => count($rules),
                'total_issues'          => count($triggered),
                'errors'                => count(array_filter($triggered, fn($r) => $r['severity'] === 'error')),
                'warnings'              => count(array_filter($triggered, fn($r) => $r['severity'] === 'warning')),
                'infos'                 => count(array_filter($triggered, fn($r) => $r['severity'] === 'info')),
                'passed'                => count($successes),
            ],
        ];
    }
}
