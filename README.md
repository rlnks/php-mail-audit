# php-mail-audit

**Email HTML quality analysis engine for PHP.**

Analyze email templates before sending — detect compatibility issues, score your HTML against major email clients, and get actionable insights to fix problems before they reach your users' inboxes.

> "Grammarly for HTML emails"

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Analyzing HTML](#analyzing-html)
- [Result Format](#result-format)
- [Bundled Rules](#bundled-rules)
- [Detection Types](#detection-types)
- [Localization](#localization)
- [Remote KB Sync](#remote-kb-sync)
- [CLI](#cli)
- [Custom Rules](#custom-rules)
- [Custom Detectors](#custom-detectors)
- [Score Calculation](#score-calculation)
- [Integration Examples](#integration-examples)
- [Running Tests](#running-tests)
- [License](#license)

---

## Requirements

- PHP 8.1 or higher
- No external dependencies — uses PHP's native `DOMDocument`
- No framework required — works with Laravel, Symfony, Slim, or plain PHP

---

## Installation

```bash
composer require rlnks/php-mail-audit
```

---

## Quick Start

```php
use MailAudit\MailAudit;

$html = file_get_contents('path/to/template.html');

$audit  = new MailAudit();
$result = $audit->analyze($html);

echo "Score: {$result['score']}/100\n";
echo "Issues: {$result['summary']['total_issues']}  |  Passed: {$result['summary']['passed']}\n\n";

foreach ($result['insights'] as $insight) {
    echo "[{$insight['severity']}] {$insight['message']}\n";
    echo "  Fix: {$insight['fix']}\n\n";
}

foreach ($result['passed'] as $check) {
    echo "[pass] {$check['message']}\n";
}
```

**Example output:**

```
Score: 79/100
Issues: 3  |  Passed: 8

[error] Flexbox is not supported in Outlook desktop and older Gmail clients.
  Fix: Replace flexbox with HTML table-based layout for maximum compatibility.

[warning] External fonts (Google Fonts, @font-face) are not supported in Outlook or Gmail.
  Fix: Define a font stack with web-safe fallback fonts: Arial, Georgia, Times New Roman.

[info] border-radius is ignored by Outlook desktop — rounded corners will appear square.
  Fix: Ensure the design is acceptable with square corners as a fallback for Outlook users.

[pass] All images have explicit width and height attributes — layout will hold when images are blocked.
[pass] No JavaScript detected — email is safe for all clients and spam filters.
[pass] No CSS Grid layout detected — good compatibility across all email clients.
```

---

## Configuration

All configuration is optional. The package works out of the box with the bundled rule set.

```php
$audit = new MailAudit(
    config: [
        'auto_update' => true,                        // enable remote KB sync
        'ttl_days'    => 7,                           // cache TTL in days
        'endpoint'    => 'https://kb.example.com/rules.json',
        'api_key'     => getenv('MAILAUDIT_API_KEY'), // null = free tier
        'cache_path'  => '/tmp/mailaudit-rules.json', // writable path
    ],
    locale: 'en',          // single locale — 'en', 'fr', 'es', 'de', 'pt'
    // locale: ['en', 'fr'], // or multiple locales at once
);
```

### Config reference

| Key | Type | Default | Description |
|---|---|---|---|
| `auto_update` | `bool` | `false` | Enable remote KB fetch |
| `ttl_days` | `int` | `7` | Days before cache is considered stale |
| `endpoint` | `string\|null` | `null` | Remote URL to fetch rules from |
| `api_key` | `string\|null` | `null` | Bearer token sent in `Authorization` header |
| `cache_path` | `string\|null` | `null` | Absolute path to the local cache file |

### Config file pattern

```php
// config/mailaudit.php
return [
    'auto_update' => true,
    'ttl_days'    => 7,
    'endpoint'    => getenv('MAILAUDIT_ENDPOINT'),
    'api_key'     => getenv('MAILAUDIT_API_KEY'),
    'cache_path'  => __DIR__ . '/../var/mailaudit-rules.json',
];

// usage
$audit = new MailAudit(require __DIR__ . '/config/mailaudit.php');
```

---

## Analyzing HTML

```php
$result = $audit->analyze(string $html): array
```

Pass the **raw HTML string** of the email template. The HTML does not need to be a complete document — partials and fragments are accepted.

```php
// From a string
$result = $audit->analyze('<div style="display:flex;">Hello</div>');

// From a file
$result = $audit->analyze(file_get_contents('emails/welcome.html'));

// From a rendered template (e.g. Twig)
$html   = $twig->render('emails/welcome.html.twig', $data);
$result = $audit->analyze($html);
```

---

## Result Format

`analyze()` returns an array with four keys:

```php
[
    'score'    => 81,          // int, 0–100
    'insights' => [ ... ],     // triggered rules (issues)
    'passed'   => [ ... ],     // rules that passed with a positive check message
    'summary'  => [ ... ],     // aggregate counts
]
```

### `score`

An integer between `0` and `100`. Higher is better. See [Score Calculation](#score-calculation).

### `insights`

Each triggered rule produces one insight:

```php
[
    'id'               => 'no-flexbox',
    'severity'         => 'error',          // 'error' | 'warning' | 'info'
    'weight'           => 15,               // nominal weight of the rule
    'message'          => 'Flexbox is not supported in Outlook desktop...',
    'fix'              => 'Replace flexbox with HTML table-based layout...',
    'affected_clients' => [
        'outlook_desktop' => ['supported' => false, 'versions' => 'all'],
        'gmail_web'       => ['supported' => false, 'versions' => '< 2022'],
        'apple_mail'      => ['supported' => true],
    ],
    'tags'      => ['css', 'layout'],
    'locations' => [
        ['line' => 12, 'column' => 5,  'offset_start' => 450,  'offset_end' => 471],
        ['line' => 34, 'column' => 9,  'offset_start' => 1205, 'offset_end' => 1226],
    ],
]
```

Each location entry points to one occurrence of the issue in the source HTML:

| Field | Type | Description |
|---|---|---|
| `line` | `int` | Line number (1-based) |
| `column` | `int` | Column within that line (1-based) |
| `offset_start` | `int` | Byte offset of the match start in the HTML string |
| `offset_end` | `int` | Byte offset of the match end (exclusive) |

This is designed for editor integration — use `offset_start`/`offset_end` with CodeMirror or Monaco `Range` objects to highlight the exact positions, and `line`/`column` to scroll and place the cursor.

```php
// Reconstruct the matched snippet from offset
$snippet = substr($html, $loc['offset_start'], $loc['offset_end'] - $loc['offset_start']);
```

When multiple locales are requested, `message` and `fix` are associative arrays keyed by locale instead of strings:

```php
// new MailAudit([], ['en', 'fr'])
'message' => [
    'en' => 'Flexbox is not supported in Outlook desktop...',
    'fr' => 'Flexbox n\'est pas supporté dans Outlook desktop...',
]
```

### `passed`

Rules that did **not** trigger and carry a `success_message` appear here — useful for showing positive feedback alongside issues (similar to htmlemailcheck.com):

```php
[
    'id'       => 'no-flexbox',
    'severity' => 'error',      // severity the rule would have had if triggered
    'message'  => 'No flexbox layout detected — good compatibility with Outlook desktop.',
    'tags'     => ['css', 'layout'],
]
```

Not every rule generates a passed item — only rules that define a `success_message` in their JSON (those where the absence of an issue is meaningfully good news).

### `summary`

```php
[
    'total_rules_checked' => 32,  // total rules evaluated
    'total_issues'        => 3,   // rules that fired
    'errors'              => 1,   // severity = error
    'warnings'            => 1,   // severity = warning
    'infos'               => 1,   // severity = info
    'passed'              => 9,   // rules that passed with a success message
]
```

---

## Bundled Rules

32 rules ship with the package, covering the most common email compatibility issues.

### Errors — break rendering in major clients

| Rule ID | Description | Weight |
|---|---|---|
| `no-flexbox` | CSS `display: flex` not supported in Outlook | 15 |
| `no-grid` | CSS `display: grid` not supported anywhere | 15 |
| `no-form-elements` | `<form>`, `<input>`, `<button>` stripped by all clients | 15 |
| `no-script` | `<script>` stripped by all clients for security reasons | 15 |
| `no-iframe` | `<iframe>` blocked by all clients | 15 |
| `no-svg` | SVG not rendered in Outlook or Gmail | 12 |
| `no-video` | `<video>` not supported in Outlook or Gmail | 12 |
| `css-at-import` | `@import` in `<style>` silently ignored by Gmail/Outlook | 10 |
| `no-audio` | `<audio>` not supported in any major client | 10 |
| `no-css-gap` | CSS `gap` / `row-gap` / `column-gap` not supported anywhere | 9 |
| `no-object-fit` | `object-fit` not supported in any major client | 8 |
| `no-css-filter` | CSS `filter` not supported in Outlook or Gmail | 8 |
| `no-clip-path` | `clip-path` not supported in any major client | 8 |

### Warnings — risky, client-dependent

| Rule ID | Description | Weight |
|---|---|---|
| `inline-css` | `<style>` blocks stripped by Gmail and Outlook | 10 |
| `no-external-fonts` | Google Fonts / `@font-face` ignored by Gmail/Outlook | 8 |
| `no-float` | `float` breaks column layouts in Outlook 2007–2019 | 8 |
| `css-class-selectors` | Gmail strips `class` attributes — class-based CSS is ineffective | 8 |
| `no-picture` | `<picture>` / `srcset` not supported in Outlook or Gmail | 8 |
| `img-dimensions` | `<img>` without `width`/`height` breaks layout when images blocked | 8 |
| `no-css-variables` | CSS `var(--x)` not supported in Outlook or Gmail | 7 |
| `missing-alt-img` | `<img>` without `alt` shows broken icons when images blocked | 7 |
| `no-css-calc` | `calc()` not supported in Outlook 2007–2019 or Gmail | 7 |
| `no-div-layout` | `<div>` layout unreliable in Outlook (box model ignored) | 6 |
| `css-media-queries` | `@media` queries ignored by Gmail (all platforms) and Outlook | 6 |
| `no-animation` | CSS `animation` / `@keyframes` ignored by Outlook and Gmail | 6 |
| `no-transform` | CSS `transform` not supported in Outlook or Gmail | 5 |

### Info — best practice recommendations

| Rule ID | Description | Weight |
|---|---|---|
| `no-position-absolute` | `position: absolute/fixed` ignored in most clients | 5 |
| `no-border-radius` | `border-radius` ignored by Outlook | 4 |
| `css-pseudo-selectors` | `:hover`, `:focus` etc. not supported in Outlook/Gmail | 4 |
| `no-box-shadow` | `box-shadow` not supported in Outlook | 3 |
| `no-transition` | CSS `transition` not supported in Outlook or Gmail | 3 |
| `table-role-presentation` | Layout tables without `role="presentation"` confuse screen readers | 3 |

---

## Detection Types

Every rule declares a `detection` object that specifies how the engine finds the issue. All detectors return exact character positions (line, column, byte offsets) for every match — see [`locations`](#insights) in the result format.

### `css_property`

Matches CSS patterns anywhere in the document — inline `style=""` attributes and `<style>` blocks.

```json
{
  "type": "css_property",
  "patterns": ["display: flex", "display:flex"]
}
```

Supports optional `"regex": true` for patterns that require precision (e.g. to avoid false positives with similar property names):

```json
{
  "type": "css_property",
  "regex": true,
  "patterns": ["(?<![a-z-])transform\\s*:"]
}
```

### `html_tag`

Fires when the specified HTML tag is present at least once. Uses `DOMDocument` for accurate parsing.

```json
{
  "type": "html_tag",
  "patterns": ["div", "svg", "form"]
}
```

Patterns are **tag names** (no angle brackets).

### `html_attribute_missing`

Fires when at least one instance of `tag` is missing a required attribute, or has an attribute with the wrong value.

```json
{
  "type": "html_attribute_missing",
  "tag": "img",
  "attributes": ["width", "height"]
}
```

With value check:

```json
{
  "type": "html_attribute_missing",
  "tag": "table",
  "attributes": ["role"],
  "attribute_value": "presentation"
}
```

### `html_content`

Matches arbitrary string patterns anywhere in the raw HTML string.

```json
{
  "type": "html_content",
  "patterns": ["fonts.googleapis.com", "@import url"]
}
```

### `style_block`

Searches exclusively inside `<style>` block content. Supports plain strings or regular expressions.

```json
{
  "type": "style_block",
  "regex": false,
  "patterns": ["@media", "@import", ":hover"]
}
```

With regex:

```json
{
  "type": "style_block",
  "regex": true,
  "patterns": ["\\.([a-zA-Z_-][\\w-]*)\\s*[{,:\\[]"]
}
```

---

## Localization

Five locales are bundled: `en` (English), `fr` (French), `es` (Spanish), `de` (German), `pt` (Portuguese).

### Single locale

```php
$audit = new MailAudit(locale: 'fr'); // or 'es', 'de', 'pt'

$result = $audit->analyze($html);
// $result['insights'][0]['message'] → string in French
// $result['insights'][0]['fix']     → string in French
```

If a locale is missing for a rule, it falls back to `en` automatically.

### Multiple locales

Pass an array to receive all translations in a single pass:

```php
$audit = new MailAudit(locale: ['en', 'fr']);

$result = $audit->analyze($html);
// $result['insights'][0]['message'] → ['en' => '...', 'fr' => '...']
// $result['insights'][0]['fix']     → ['en' => '...', 'fr' => '...']
// $result['passed'][0]['message']   → ['en' => '...', 'fr' => '...']
```

This is useful when building multi-language UIs without running `analyze()` twice.

### Adding a locale

Add the locale key to `message`, `fix`, and optionally `success_message` in each rule JSON:

```json
{
  "message": {
    "en": "Flexbox is not supported in Outlook.",
    "fr": "Flexbox n'est pas supporté dans Outlook.",
    "de": "Flexbox wird in Outlook nicht unterstützt."
  },
  "fix": {
    "en": "Use HTML tables for layout.",
    "fr": "Utilisez des tables HTML pour la mise en page.",
    "de": "Verwenden Sie HTML-Tabellen für das Layout."
  }
}
```

---

## Remote KB Sync

By default the package uses the bundled rule set. You can point it at a remote endpoint to receive updated rules without a Composer update.

### How it works

```
Remote endpoint
      ↓  fetched when cache is stale or missing
Local cache file  (cache_path)
      ↓  fallback if fetch fails or auto_update = false
Bundled rules  (rules/*.json in the package)
```

### Enabling sync

```php
$audit = new MailAudit([
    'auto_update' => true,
    'ttl_days'    => 7,                                        // re-fetch after 7 days
    'endpoint'    => 'https://kb.mailaudit.io/rules.json',
    'api_key'     => getenv('MAILAUDIT_API_KEY'),              // optional, pro tier
    'cache_path'  => __DIR__ . '/var/mailaudit-rules.json',    // must be writable
]);
```

### Tier behavior

| Condition | Rules returned |
|---|---|
| No `api_key` | Free rules only |
| Valid `api_key` | Free + Pro rules |
| Expired / invalid `api_key` | 401 response → silent fallback to bundled rules |

### Cache behavior

| Situation | Behavior |
|---|---|
| First install, no cache | Bundled rules |
| Cache exists, not stale | Cache used |
| Cache stale or missing | Fetch from endpoint, write cache |
| Fetch fails (network error) | Bundled rules (silent fallback) |
| `auto_update = false` | Always bundled rules |

---

## CLI

A command-line tool is available at `vendor/bin/mailaudit` after installation.

### `sync` — refresh the local cache

```bash
vendor/bin/mailaudit sync [options]
```

**Using environment variables:**

```bash
export MAILAUDIT_ENDPOINT=https://kb.mailaudit.io/rules.json
export MAILAUDIT_API_KEY=your-api-key
export MAILAUDIT_CACHE_PATH=/var/cache/mailaudit-rules.json

vendor/bin/mailaudit sync
```

**Using a config file:**

```bash
vendor/bin/mailaudit sync --config=config/mailaudit.php
```

**Dry run** (fetch but do not write cache):

```bash
vendor/bin/mailaudit sync --config=config/mailaudit.php --dry-run
```

### Available options

| Option | Description |
|---|---|
| `--config=<path>` | PHP file returning a config array |
| `--dry-run` | Fetch without writing the cache |

### Environment variables

| Variable | Description |
|---|---|
| `MAILAUDIT_ENDPOINT` | Remote KB endpoint URL |
| `MAILAUDIT_API_KEY` | API key for pro tier |
| `MAILAUDIT_CACHE_PATH` | Absolute path to the local cache file |

---

## Custom Rules

You can add your own rules without modifying the package.

### 1. Create a rule JSON file

```json
{
  "id": "no-video",
  "version": "1.0",
  "updated_at": "2026-05-09",
  "source": "https://www.caniemail.com/features/html-video/",
  "tier": "free",
  "severity": "error",
  "weight": 12,
  "tags": ["html", "media"],
  "detection": {
    "type": "html_tag",
    "patterns": ["video"]
  },
  "affected_clients": {
    "outlook_desktop": { "supported": false, "versions": "all" },
    "gmail_web": { "supported": false }
  },
  "message": {
    "en": "<video> elements are not supported in Outlook or Gmail.",
    "fr": "Les éléments <video> ne sont pas supportés dans Outlook ni Gmail."
  },
  "fix": {
    "en": "Use a linked image (GIF or static) as a fallback for video content.",
    "fr": "Utiliser une image liée (GIF ou statique) comme fallback pour le contenu vidéo."
  }
}
```

### 2. Load it alongside the bundled rules

```php
use MailAudit\Loader\RuleLoader;
use MailAudit\Analysis\RuleEngine;
use MailAudit\Analysis\ScoringEngine;
use MailAudit\Feedback\FeedbackGenerator;

$bundled = (new RuleLoader())->load();
$custom  = [json_decode(file_get_contents('rules/no-video.json'), true)];
$rules   = array_merge($bundled, $custom);

$triggered = (new RuleEngine($rules))->analyze($html);
$score     = (new ScoringEngine())->calculate($triggered);
$insights  = (new FeedbackGenerator('en'))->generate($triggered);
```

Or subclass `MailAudit` to make this reusable in your project.

### Rule JSON reference

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | `string` | Yes | Unique identifier |
| `version` | `string` | Yes | Semver string, bumped on changes |
| `updated_at` | `string` | Yes | ISO date `YYYY-MM-DD` |
| `source` | `string` | No | Reference URL (e.g. caniemail.com) |
| `tier` | `string` | Yes | `free` or `pro` |
| `severity` | `string` | Yes | `error`, `warning`, or `info` |
| `weight` | `int` | Yes | Points deducted from score (0–100) |
| `tags` | `string[]` | No | Categorization tags |
| `detection` | `object` | Yes | See [Detection Types](#detection-types) |
| `affected_clients` | `object` | No | Per-client support data |
| `message` | `object` | Yes | Locale-keyed problem description |
| `fix` | `object` | Yes | Locale-keyed fix suggestion |
| `success_message` | `object` | No | Locale-keyed message shown when the rule passes. When present, the rule appears in the `passed` array of the result. |

---

## Custom Detectors

You can register new detection types to support custom rule patterns.

### 1. Implement `DetectorInterface`

```php
use MailAudit\Detection\DetectorInterface;

class MjmlTagDetector implements DetectorInterface
{
    public function matches(string $html, array $detection): bool
    {
        foreach ($detection['tags'] ?? [] as $tag) {
            if (str_contains($html, "<mj-{$tag}")) {
                return true;
            }
        }
        return false;
    }
}
```

### 2. Register it with the factory

```php
use MailAudit\Detection\DetectorFactory;

DetectorFactory::register('mjml_tag', MjmlTagDetector::class);
```

### 3. Use it in a rule JSON

```json
{
  "detection": {
    "type": "mjml_tag",
    "tags": ["section", "column"]
  }
}
```

Registration is global and static — register once at application bootstrap before calling `analyze()`.

---

## Score Calculation

The score starts at **100**. Each triggered rule deducts a weighted amount based on its severity:

```
deduction = weight × severity_multiplier

severity multipliers:
  error   → 1.0  (full weight)
  warning → 0.6  (60% of weight)
  info    → 0.3  (30% of weight)

score = max(0, round(100 - sum(deductions)))
```

**Example:**

| Rule triggered | Severity | Weight | Multiplier | Deduction |
|---|---|---|---|---|
| `no-flexbox` | error | 15 | × 1.0 | 15.0 |
| `no-external-fonts` | warning | 8 | × 0.6 | 4.8 |
| `no-border-radius` | info | 4 | × 0.3 | 1.2 |
| **Total deduction** | | | | **21.0** |
| **Final score** | | | | **79 / 100** |

The score cannot go below 0.

### Severity vs weight

Severity (`error`, `warning`, `info`) is a **qualitative label** indicating the nature of the problem. Weight is the **nominal importance** of the rule. The multiplier ensures that warnings and info items have a proportionally smaller impact on the score than blocking errors, so a well-crafted email with minor compatibility caveats scores realistically (75–90) rather than being penalized alongside fundamentally broken emails.

---

## Integration Examples

### Vanilla PHP

```php
use MailAudit\MailAudit;

$result = (new MailAudit())->analyze(file_get_contents('email.html'));

if ($result['score'] < 80) {
    foreach ($result['insights'] as $insight) {
        if ($insight['severity'] === 'error') {
            throw new RuntimeException("Email has blocking issues: {$insight['message']}");
        }
    }
}
```

### Symfony

```php
// config/services.yaml
services:
    MailAudit\MailAudit:
        arguments:
            $config:
                auto_update: true
                ttl_days: 7
                endpoint: '%env(MAILAUDIT_ENDPOINT)%'
                api_key: '%env(MAILAUDIT_API_KEY)%'
                cache_path: '%kernel.cache_dir%/mailaudit-rules.json'

// In a service or controller
public function __construct(private MailAudit $audit) {}

public function preview(string $html): array
{
    return $this->audit->analyze($html);
}
```

### Laravel

```php
// config/mailaudit.php
return [
    'auto_update' => true,
    'ttl_days'    => 7,
    'endpoint'    => env('MAILAUDIT_ENDPOINT'),
    'api_key'     => env('MAILAUDIT_API_KEY'),
    'cache_path'  => storage_path('app/mailaudit-rules.json'),
];

// AppServiceProvider
$this->app->singleton(\MailAudit\MailAudit::class, fn() =>
    new \MailAudit\MailAudit(config('mailaudit'))
);
```

### In a CI/CD pipeline (GitHub Actions)

```yaml
- name: Audit email templates
  run: |
    php -r "
      require 'vendor/autoload.php';
      \$audit  = new \MailAudit\MailAudit();
      \$result = \$audit->analyze(file_get_contents('templates/welcome.html'));
      if (\$result['score'] < 70) {
          echo 'Email quality score too low: ' . \$result['score'] . '/100\n';
          exit(1);
      }
      echo 'Score: ' . \$result['score'] . "/100 — OK\n";
    "
```

---

## Running Tests

```bash
composer install
vendor/bin/phpunit
```

Run static analysis:

```bash
vendor/bin/phpstan analyse
```

---

## License

[MIT](LICENSE) — © 2026 rlnks
