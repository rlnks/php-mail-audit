<?php

/**
 * Syncs affected_clients data from caniemail.com API into our bundled rule JSON files.
 *
 * Maps caniemail feature slugs to our rule IDs via SLUG_TO_RULE_ID.
 * Updates updated_at and affected_clients on each matched rule.
 * Skips rules with no caniemail mapping (custom rules).
 *
 * Usage: php scripts/sync-caniemail.php [--dry-run]
 */

declare(strict_types=1);

const CANIEMAIL_API = 'https://www.caniemail.com/api/data.json';
const RULES_DIR     = __DIR__ . '/../rules';

// Maps caniemail feature slug → our rule ID
const SLUG_TO_RULE_ID = [
    'css-flexbox'            => 'no-flexbox',
    'css-grid'               => 'no-grid',
    'html-style'             => 'inline-css',
    'html-img'               => 'img-dimensions',
    'css-position'           => 'no-position-absolute',
    'html-div'               => 'no-div-layout',
    'css-at-font-face'       => 'no-external-fonts',
    'css-variables'          => 'no-css-variables',
    'css-float'              => 'no-float',
    'css-border-radius'      => 'no-border-radius',
    'css-box-shadow'         => 'no-box-shadow',
    'image-svg'              => 'no-svg',
    'html-form'              => 'no-form-elements',
    'html-attributes-class'  => 'css-class-selectors',
    'css-pseudo-class-hover' => 'css-pseudo-selectors',
    'css-at-media'           => 'css-media-queries',
    'css-at-import'          => 'css-at-import',
];

// Maps caniemail client key → our client key(s)
const CLIENT_MAP = [
    'apple-mail'    => ['apple_mail'],
    'gmail'         => ['gmail_web'],
    'outlook'       => ['outlook_desktop', 'outlook_web'],
    'yahoo-mail'    => ['yahoo_mail'],
    'samsung-email' => ['samsung_email'],
    'thunderbird'   => ['thunderbird'],
    'orange'        => [],
    'sfr'           => [],
    'free-fr'       => [],
    'laposte'       => [],
    'aol'           => ['aol_mail'],
    'protonmail'    => ['protonmail'],
    'hey'           => [],
];

// caniemail client sub-keys that map to outlook_desktop vs outlook_web
const OUTLOOK_DESKTOP_KEYS = ['windows-11', 'windows-10', 'windows-7', 'windows-xp'];
const OUTLOOK_WEB_KEYS     = ['office-365'];

$isDryRun = in_array('--dry-run', $argv ?? [], true);

echo "Fetching caniemail.com dataset...\n";
$raw = @file_get_contents(CANIEMAIL_API);
if ($raw === false) {
    fwrite(STDERR, "ERROR: Could not fetch " . CANIEMAIL_API . "\n");
    exit(1);
}

$data = json_decode($raw, true);
if (!isset($data['data']) || !is_array($data['data'])) {
    fwrite(STDERR, "ERROR: Unexpected API response format\n");
    exit(1);
}

echo "Fetched " . count($data['data']) . " features from caniemail.com\n";

// Index caniemail features by slug
$bySlug = [];
foreach ($data['data'] as $feature) {
    if (isset($feature['slug'])) {
        $bySlug[$feature['slug']] = $feature;
    }
}

$updated = 0;
$skipped = 0;

foreach (SLUG_TO_RULE_ID as $slug => $ruleId) {
    $ruleFile = RULES_DIR . '/' . $ruleId . '.json';

    if (!file_exists($ruleFile)) {
        echo "  SKIP (no rule file): {$ruleId}\n";
        $skipped++;
        continue;
    }

    if (!isset($bySlug[$slug])) {
        echo "  SKIP (not in caniemail): {$slug}\n";
        $skipped++;
        continue;
    }

    $rule    = json_decode(file_get_contents($ruleFile), true);
    $feature = $bySlug[$slug];
    $stats   = $feature['stats'] ?? [];

    $affectedClients = buildAffectedClients($stats);

    $changed = $rule['affected_clients'] !== $affectedClients;

    if ($changed) {
        $rule['affected_clients'] = $affectedClients;
        $rule['updated_at']       = date('Y-m-d');
        $rule['version']          = bumpPatch($rule['version'] ?? '1.0');

        if (!$isDryRun) {
            file_put_contents($ruleFile, json_encode($rule, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        }

        echo "  UPDATED: {$ruleId}" . ($isDryRun ? ' (dry-run)' : '') . "\n";
        $updated++;
    } else {
        echo "  OK (no change): {$ruleId}\n";
    }
}

echo "\nDone. Updated: {$updated}, Skipped: {$skipped}\n";

// --- helpers ---

function buildAffectedClients(array $stats): array
{
    $result = [];

    foreach ($stats as $client => $versions) {
        if (!isset(CLIENT_MAP[$client])) {
            continue;
        }

        if ($client === 'outlook') {
            $desktopSupport = resolveSupport($versions, OUTLOOK_DESKTOP_KEYS);
            $webSupport     = resolveSupport($versions, OUTLOOK_WEB_KEYS);

            if ($desktopSupport !== null) {
                $result['outlook_desktop'] = $desktopSupport;
            }
            if ($webSupport !== null) {
                $result['outlook_web'] = $webSupport;
            }
            continue;
        }

        $support = resolveSupport($versions, array_keys($versions));
        foreach (CLIENT_MAP[$client] as $ourKey) {
            if ($support !== null) {
                $result[$ourKey] = $support;
            }
        }
    }

    return $result;
}

function resolveSupport(array $versions, array $keys): ?array
{
    $values = [];
    foreach ($keys as $key) {
        if (isset($versions[$key])) {
            $values[] = $versions[$key];
        }
    }

    if (empty($values)) {
        return null;
    }

    $supported = array_filter($values, fn($v) => $v === 'y');
    $notSupported = array_filter($values, fn($v) => $v === 'n');
    $partial = array_filter($values, fn($v) => $v === 'p');

    if (count($supported) === count($values)) {
        return ['supported' => true];
    }
    if (count($notSupported) === count($values)) {
        return ['supported' => false, 'versions' => 'all'];
    }
    if (!empty($partial)) {
        return ['supported' => 'partial'];
    }

    // Mixed: majority wins
    return count($supported) >= count($notSupported)
        ? ['supported' => true]
        : ['supported' => false, 'versions' => 'some'];
}

function bumpPatch(string $version): string
{
    $parts = explode('.', $version);
    $parts[1] = (int) ($parts[1] ?? 0) + 1;
    return implode('.', $parts);
}
