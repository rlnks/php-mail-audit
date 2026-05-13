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
Score: 84/100
Issues: 4  |  Passed: 12

[error] Form elements (<form>, <input>, <button>) are stripped or non-functional in virtually all email clients.
  Fix: Replace interactive forms with a CTA button linking to a landing page that hosts the form.

[warning] @import inside a <style> block is not supported in Gmail or Outlook.
  Fix: Replace @import with a <link> tag, and always define inline font-family stacks with web-safe fallbacks.

[info] External font detected — supported in Apple Mail and some modern clients, but not in Gmail or Outlook.
  Fix: Always define a font-family stack with web-safe fallbacks inline on every element.

[info] Div elements found — acceptable for wrapping content, but prefer <td> for layout in email.

[pass] No flexbox layout detected — good compatibility with Outlook desktop.
[pass] All images have explicit width and height attributes — layout will hold when images are blocked.
[pass] No external fonts detected — consistent rendering across all clients.
[pass] No JavaScript detected — email is safe for all clients and spam filters.
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
    'total_rules_checked' => 56,  // total rules evaluated
    'total_issues'        => 3,   // rules that fired
    'errors'              => 1,   // severity = error
    'warnings'            => 1,   // severity = warning
    'infos'               => 1,   // severity = info
    'passed'              => 9,   // rules that passed with a success message
]
```

---

## Bundled Rules

56 rules ship with the package. The philosophy: **flag bad usage, not feature presence**. Media queries, hover states, and class selectors used correctly (with inline fallbacks) score well. The engine penalizes the *absence* of fallbacks, not the features themselves.

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
| `no-audio` | `<audio>` not supported in any major client | 10 |
| `no-css-gap` | CSS `gap` / `row-gap` / `column-gap` not supported anywhere | 9 |
| `no-object-fit` | `object-fit` not supported in any major client | 8 |
| `no-css-filter` | CSS `filter` not supported in Outlook or Gmail | 8 |
| `no-clip-path` | `clip-path` not supported in any major client | 8 |
| `no-css-variables` | CSS `var()` used **without a fallback value** — Outlook and Gmail silently ignore the property entirely | 7 |

### Warnings — real problems when fallbacks are missing

| Rule ID | Description | Weight |
|---|---|---|
| `style-no-inline-fallback` | `<style>` block present but **zero** inline styles — layout breaks entirely when Gmail/Outlook strip the style block | 12 |
| `html-too-large` | HTML exceeds 102 KB — Gmail clips the message and shows a "Message clipped" link | 10 |
| `media-no-inline-base` | `@media` queries present but no inline style baseline — responsive layout has no fallback for Gmail/Outlook | 10 |
| `img-dimensions` | `<img>` without `width`/`height` — layout breaks when images are blocked | 8 |
| `no-float` | `float` breaks column layouts in Outlook 2007–2019 | 8 |
| `font-no-fallback` | External font loaded but no inline `font-family` fallback stack — text falls back to client default when font is stripped | 8 |
| `no-picture` | `<picture>` / `srcset` not supported in Outlook or Gmail | 8 |
| `missing-alt-img` | `<img>` without `alt` shows broken icons when images blocked | 7 |
| `no-css-calc` | `calc()` not supported in Outlook 2007–2019 or Gmail | 7 |
| `missing-https` | HTTP (non-HTTPS) `src`/`href` detected — email clients block mixed content, breaking images and links | 6 |
| `no-div-layout` | `<div>` with layout CSS (`width`, `float`, `margin`, etc.) — box model ignored by Outlook | 6 |
| `no-animation` | CSS `animation` / `@keyframes` ignored by Outlook and Gmail | 6 |
| `url-unencoded` | Unencoded space in a URL (`href` or `src`) — breaks the link in all clients | 5 |
| `css-at-import` | `@import` in `<style>` silently ignored by Gmail/Outlook | 5 |
| `no-transform` | CSS `transform` not supported in Outlook or Gmail | 5 |
| `css-at-import-no-link` | `@import` in `<style>` with no `<link>` fallback — font will not load in clients that strip `<style>` blocks | 5 |
| `link-no-text` | `<a>` with no accessible text or descriptive image `alt` — screen readers announce it as an unlabeled link | 5 |
| `text-image-ratio` | Email is mostly images with very little readable text — high spam filter risk, renders blank when images are blocked | 6 |
| `email-max-width` | Fixed-width `<table>` over 600 px — overflows the Outlook rendering pane and narrow viewports | 5 |

### Info — usage noted, minimal score impact

Rules in this category flag the **presence** of a feature that is often used correctly as progressive enhancement. They fire when the feature is detected, regardless of fallback quality — the corresponding warning-level rules handle the bad cases.

| Rule ID | Description | Weight |
|---|---|---|
| `no-position-absolute` | `position: absolute/fixed` ignored in most clients | 5 |
| `no-border-radius` | `border-radius` ignored by Outlook desktop | 4 |
| `no-box-shadow` | `box-shadow` not supported in Outlook | 3 |
| `no-transition` | CSS `transition` not supported in Outlook or Gmail | 3 |
| `table-role-presentation` | Layout tables without `role="presentation"` confuse screen readers | 3 |
| `preheader-missing` | No preheader div found — inbox preview will show unrelated body text | 3 |
| `inline-css` | `<style>` block present — acceptable when inline fallback styles are defined | 2 |
| `css-class-selectors` | Class-based CSS in `<style>` — Gmail strips `class` attributes | 2 |
| `css-media-queries` | `@media` queries detected — great when paired with inline styles | 2 |
| `no-external-fonts` | External font loaded — supported in Apple Mail, not Gmail/Outlook | 2 |
| `missing-lang` | `<html>` without `lang` attribute — screen readers and translation tools rely on it | 2 |
| `missing-viewport` | No `<meta name="viewport">` — mobile clients may render at desktop width | 2 |
| `preheader-too-long` | Preheader text exceeds 150 characters (filler excluded) — most clients truncate at 85–150 chars | 2 |
| `css-pseudo-selectors` | `:hover`, `:focus` etc. detected — ignored in Outlook/Gmail, use as enhancement only | 1 |
| `div-content` | `<div>` used as content wrapper — acceptable, but `<td>` preferred for compatibility | 1 |
| `empty-alt-img` | `<img alt="">` detected — verify image is truly decorative and carries no information | 1 |
| `nbsp-missing` | Regular space between a number and a currency/unit symbol — may break across lines on narrow screens | 1 |
| `heading-order` | Heading levels skipped (e.g. `<h1>` directly followed by `<h3>`) — hurts accessibility and screen reader navigation | 2 |
| `tracking-pixel` | 1×1 tracking pixel detected — note that Apple Mail Privacy Protection may trigger false open events | 0 |
| `font-family-unquoted` | Multi-word font name used without quotes in `font-family` — may be misinterpreted by some CSS parsers | 2 |
| `missing-charset` | No character encoding declaration in `<head>` — some clients may misrender special characters | 2 |
| `missing-doctype` | No `<!DOCTYPE html>` declaration — some clients fall back to quirks mode rendering | 2 |
| `table-cellspacing` | `<table>` without `cellpadding="0" cellspacing="0"` — default cell spacing varies across clients | 2 |
| `missing-body-bgcolor` | No background color on `<body>` — some clients display a grey or off-white default background | 1 |

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

With `"only_empty": true` — fires when the attribute is present but empty (`alt=""`). Useful for distinguishing missing alt text from intentionally empty alt text:

```json
{
  "type": "html_attribute_missing",
  "tag": "img",
  "attributes": ["alt"],
  "only_empty": true
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

Supports `"regex": true` for patterns requiring precision. The `~` character is used as the regex delimiter internally — escape it as `\\~` if needed in a pattern:

```json
{
  "type": "html_content",
  "regex": true,
  "patterns": ["src\\s*=\\s*[\"']http://", "href\\s*=\\s*[\"']http://"]
}
```

### `html_tag_with_style`

Fires when a tag is present **and** its inline `style` attribute contains one of the given CSS patterns. Useful for distinguishing structural divs from decorative ones.

```json
{
  "type": "html_tag_with_style",
  "tag": "div",
  "css_patterns": ["width:", "float:", "margin:"]
}
```

Supports `"regex": true` for precise matching (e.g. to avoid matching `max-width:` when looking for `width:`):

```json
{
  "type": "html_tag_with_style",
  "tag": "div",
  "regex": true,
  "css_patterns": ["(?<![a-z-])width\\s*:\\s*(?!0)", "float\\s*:"]
}
```

### `correlation`

Fires when a **trigger** pattern is present but an expected **fallback** pattern is absent. Use this to flag bad *usage* of a feature rather than its mere presence.

```json
{
  "type": "correlation",
  "trigger": {
    "type": "html_content",
    "patterns": ["fonts.googleapis.com", "@font-face"]
  },
  "fallback": {
    "type": "css_property",
    "regex": true,
    "patterns": ["font-family\\s*:[^;\"']*,"]
  }
}
```

The rule above fires only when an external font is loaded **and** no inline `font-family` fallback stack is found — correctly scoring emails that use custom fonts with proper fallbacks.

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

### `preheader`

Detects the standard email preheader pattern — a `<div>` with both `display:none` and `overflow:hidden` in its inline `style`. Two modes:

- **`missing`** — fires when no preheader div is found in a complete document (one that contains a `<body>` tag). Fragments are skipped.
- **`too_long`** — fires when the visible preheader text exceeds `max_length` characters. Filler characters (`&nbsp;`, `&zwnj;`, and their Unicode equivalents) are stripped before measuring, so filling with spacers does not inflate the count.

```json
{
  "type": "preheader",
  "mode": "missing"
}
```

```json
{
  "type": "preheader",
  "mode": "too_long",
  "max_length": 150
}
```

### `html_metric`

Measures a numeric property of the HTML and fires when it exceeds a threshold. Currently supported metrics:

| Metric | Description |
|---|---|
| `size` | Total byte length of the HTML string (`strlen`) |

```json
{
  "type": "html_metric",
  "metric": "size",
  "threshold": 102400
}
```

### `heading_order`

Detects heading levels that are skipped in document order (e.g. `<h1>` directly followed by `<h3>`). No configuration options.

```json
{
  "type": "heading_order"
}
```

### `html_link_no_text`

Fires when an `<a>` element has no accessible text — no text node content and no child `<img>` with a non-empty `alt`. No configuration options.

```json
{
  "type": "html_link_no_text"
}
```

### `html_tracking_pixel`

Detects `<img>` elements with `width="1"` and `height="1"`, the classic open-tracking pattern. No configuration options.

```json
{
  "type": "html_tracking_pixel"
}
```

### `css_font_family`

Detects multi-word font names used without quotes in `font-family` declarations (e.g. `font-family: Open Sans` instead of `font-family: 'Open Sans'`). No configuration options.

```json
{
  "type": "css_font_family"
}
```

### `html_table_width`

Fires when a `<table>` element carries an inline `width` in pixels that exceeds `max_width` (default: 600).

```json
{
  "type": "html_table_width",
  "max_width": 600
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

### `audit` — analyze an email file

```bash
vendor/bin/mailaudit audit path/to/email.html
vendor/bin/mailaudit audit path/to/email.html --locale=fr
vendor/bin/mailaudit audit path/to/email.html --format=json
```

**Example output (text):**

```
SCORE: 84/100 — email.html
────────────────────────────────────────────
[ERROR  ] no-flexbox                 Flexbox is not supported in Outlook...
[WARN   ] missing-https              HTTP links detected — email clients b...
[INFO   ] div-content                <div> used as content wrapper — acce...
────────────────────────────────────────────
✓ no-script   ✓ img-dimensions   ✓ table-role-presentation
```

**With `--format=json`**, the full `analyze()` result array is printed as pretty-printed JSON — same structure as documented in [Result Format](#result-format).

### `audit` options

| Option | Description |
|---|---|
| `--locale=<code>` | Locale for messages: `en` (default), `fr`, `es`, `de`, `pt` |
| `--format=json` | Output the full result as JSON instead of the formatted summary |

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
| `no-svg` | error | 12 | × 1.0 | 12.0 |
| `style-no-inline-fallback` | warning | 12 | × 0.6 | 7.2 |
| `no-css-calc` | warning | 7 | × 0.6 | 4.2 |
| `css-media-queries` | info | 2 | × 0.3 | 0.6 |
| **Total deduction** | | | | **24.0** |
| **Final score** | | | | **76 / 100** |

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
