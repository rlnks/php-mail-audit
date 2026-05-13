# MailAudit — TODO pour prochaine session

Repo: `rlnks/php-mail-audit` (Packagist)
Branche: `main`, dernier tag: `v1.3.3`
Tests: 124/124 verts

---

## 🐛 BUG PRIORITAIRE — font-no-fallback faux positif

**Fichier:** `rules/font-no-fallback.json`

**Symptôme:** La règle `font-no-fallback` fire incorrectement sur des éléments qui
ont pourtant une pile de fallback complète, par exemple:

```html
<p style="font-family:'Kia Signature Regular', 'Open Sans', Arial, 'Helvetica Neue', Helvetica, sans-serif;">
```

**Cause:** Le pattern de détection du fallback est:
```json
"patterns": ["font-family\\s*:[^;\"']*,"]
```

La classe `[^;"']*` exclut les guillemets, donc quand le premier nom de police
est entre quotes (`'Kia Signature Regular'`), le moteur s'arrête à la première
quote et ne trouve jamais la virgule → fallback non détecté → règle fire à tort.

**Fix à appliquer:** Remplacer le pattern par un qui gère les trois formes:
```json
"patterns": ["font-family\\s*:\\s*(?:'[^']*'|\"[^\"]*\"|[^;'\"]+)\\s*,"]
```

Ce pattern matche:
- `font-family:'Kia Signature Regular',` → quoted single ✓
- `font-family:"Open Sans",` → quoted double ✓
- `font-family: Arial,` → unquoted ✓

Ajouter un test dans `MailAuditTest.php`:
```php
public function test_font_no_fallback_does_not_fire_with_quoted_first_font(): void
{
    $html = '<style>@font-face{font-family:"Kia";src:url(x.woff);}</style>'
          . '<p style="font-family:\'Kia Signature Regular\', \'Open Sans\', Arial, sans-serif;">Hello</p>';
    $this->assertRuleNotTriggered($html, 'font-no-fallback');
}
```

---

## 📋 RÈGLES À AJOUTER

### `unsubscribe-missing` (info, w=3)
Détecte les emails sans lien de désinscription (CAN-SPAM / CASL compliance).
- Chercher les mots-clés "unsubscribe", "se désabonner", "désinscription" dans les liens `<a>`
- Seulement pour les documents complets (avec `<body>`) — fragments ignorés
- Détection: `correlation` trigger=`<body`, fallback=`html_content` regex sur href contenant
  "unsub", "désabon", "désinscri", "optout", "opt-out"

---

## 🖥️ CLI — Commande `audit`

Actuellement `vendor/bin/mailaudit` n'a que la commande `sync`.
Ajouter une commande `audit` :

```bash
vendor/bin/mailaudit audit path/to/email.html
vendor/bin/mailaudit audit path/to/email.html --locale=fr
vendor/bin/mailaudit audit path/to/email.html --format=json
```

Sortie console attendue:
```
SCORE: 84/100 — email.html
────────────────────────────────────
[ERROR  ] no-flexbox         Flexbox not supported in Outlook
[WARN   ] missing-https      HTTP links detected
[INFO   ] div-content        <div> used as content wrapper
────────────────────────────────────
✓ no-script    ✓ img-dimensions    ✓ table-role-presentation
```

Fichier à créer/modifier: `bin/mailaudit` (le CLI existant) ou `src/Cli/AuditCommand.php`

---

## 📦 JS PORT — `@rlnks/mail-audit` (npm)

**Nouveau dépôt:** `rlnks/js-mail-audit`
**Package npm:** `@rlnks/mail-audit`
**Langage:** TypeScript

### Architecture
- Les `rules/*.json` du repo PHP sont copiés tels quels (langage-agnostique)
- Mêmes détecteurs réécrits en TypeScript
- `HtmlTagDetector` utilise `DOMDocument` en PHP → remplacer par `parse5` (Node) ou `DOMParser` (browser)
- API identique au PHP:
  ```ts
  import { MailAudit } from '@rlnks/mail-audit';
  const result = await new MailAudit().analyze(html);
  // result.score, result.insights, result.passed, result.summary
  ```
- Même format de résultat (score, locations avec offset_start/offset_end, etc.)
- Zéro dépendance runtime sauf parse5 pour le DOM

### Structure de fichiers suggérée
```
js-mail-audit/
  src/
    MailAudit.ts
    Detection/
      AbstractDetector.ts
      CssPropertyDetector.ts
      HtmlTagDetector.ts        ← utilise parse5
      HtmlContentDetector.ts
      CorrelationDetector.ts
      PreheaderDetector.ts
      HtmlMetricDetector.ts
      HeadingOrderDetector.ts
      HtmlLinkNoTextDetector.ts
      HtmlTrackingPixelDetector.ts
      CssFontFamilyDetector.ts
      HtmlTableWidthDetector.ts
      ... (tous les détecteurs PHP à porter)
    Analysis/
      RuleEngine.ts
      ScoringEngine.ts
    Loader/
      RuleLoader.ts
  rules/                        ← copie des JSON du repo PHP
  tests/
  package.json
  tsconfig.json
```

### Plan d'implémentation
1. Setup repo, tsconfig, jest pour tests
2. Porter les détecteurs un par un (commencer par les plus simples: `html_content`, `css_property`, `style_block`)
3. `HtmlTagDetector` en dernier (nécessite parse5)
4. Tests: même suite de cas que le PHP
5. Publier sur npm

---

## 🔌 EXTENSION VS CODE — `rlnks.mail-audit`

**Nouveau dépôt:** `rlnks/vscode-mail-audit`
**VS Code Marketplace:** `rlnks.mail-audit`
**Dépendance:** `@rlnks/mail-audit` (le package JS ci-dessus)

### Fonctionnalités
- **Diagnostics inline:** squiggles (vagues) rouge/orange/bleu sur les positions exactes
  des issues, grâce aux `offset_start`/`offset_end` déjà calculés par le moteur
- **Hover tooltip:** au survol d'une issue, affiche `message` + `Fix: ...`
- **Panel "Mail Audit":** vue latérale avec score, liste des issues et des passed
- **Déclenchement:** à la sauvegarde du fichier (et optionnellement en temps réel)
- **Activation:** sur les fichiers `.html` et `.htm` (configurable)

### Architecture VS Code
```
vscode-mail-audit/
  src/
    extension.ts          ← point d'entrée, active les providers
    AuditProvider.ts      ← lance l'audit, produit les Diagnostic[]
    PanelProvider.ts      ← WebviewPanel avec le rapport complet
  package.json            ← contributes.languages, commands, views
```

### Plan d'implémentation
1. Scaffolding avec `yo code` (générateur officiel VS Code)
2. `AuditProvider`: écoute `onDidSaveTextDocument`, appelle `@rlnks/mail-audit`,
   convertit les `locations` en `vscode.Diagnostic`
3. `PanelProvider`: WebView HTML affichant score + tableau des résultats
4. Publier sur VS Code Marketplace via `vsce`

---

## 📝 README À METTRE À JOUR

Le README est en retard depuis v1.3.0. Sections à mettre à jour:

- **Bundled Rules**: 51 → 61+ règles (ajouter toutes les règles v1.3.1, v1.3.2, v1.3.3)
- **Detection Types**: ajouter `heading_order`, `link_no_text`, `tracking_pixel`,
  `css_font_family`, `table_max_width`
- **Score example**: mettre à jour `total_rules_checked`

Nouvelles règles depuis v1.3.1 non documentées:
- v1.3.1: `heading-order`, `link-no-text`, `text-image-ratio`, `css-at-import-no-link`
  + `css-at-import` changé de warning → info
- v1.3.2: `tracking-pixel` (w=0), `font-family-unquoted`
- v1.3.3: `missing-charset`, `missing-doctype`, `table-cellspacing`,
  `email-max-width`, `missing-body-bgcolor`
