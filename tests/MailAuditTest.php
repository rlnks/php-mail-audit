<?php

namespace MailAudit\Tests;

use MailAudit\MailAudit;
use PHPUnit\Framework\TestCase;

class MailAuditTest extends TestCase
{
    private MailAudit $audit;

    protected function setUp(): void
    {
        $this->audit = new MailAudit();
    }

    public function test_clean_email_scores_100(): void
    {
        $html = '<table role="presentation"><tr><td style="color:red;">Hello</td></tr></table>';

        $result = $this->audit->analyze($html);

        $this->assertSame(100, $result['score']);
        $this->assertCount(0, $result['insights']);
    }

    public function test_result_has_expected_structure(): void
    {
        $result = $this->audit->analyze('<table><tr><td>test</td></tr></table>');

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total_rules_checked', $result['summary']);
        $this->assertArrayHasKey('errors', $result['summary']);
        $this->assertArrayHasKey('warnings', $result['summary']);
        $this->assertArrayHasKey('infos', $result['summary']);
        $this->assertArrayHasKey('passed', $result['summary']);
    }

    public function test_triggered_insight_has_locations(): void
    {
        $result  = $this->audit->analyze('<div style="display: flex;">test</div>');
        $insight = array_values(array_filter($result['insights'], fn($i) => $i['id'] === 'no-flexbox'))[0];

        $this->assertArrayHasKey('locations', $insight);
        $this->assertNotEmpty($insight['locations']);

        $loc = $insight['locations'][0];
        $this->assertArrayHasKey('line', $loc);
        $this->assertArrayHasKey('column', $loc);
        $this->assertArrayHasKey('offset_start', $loc);
        $this->assertArrayHasKey('offset_end', $loc);
        $this->assertGreaterThan(0, $loc['offset_end'] - $loc['offset_start']);
    }

    public function test_passed_contains_rules_with_success_message(): void
    {
        $html   = '<table role="presentation"><tr><td style="color:red;">Hello</td></tr></table>';
        $result = $this->audit->analyze($html);

        $passedIds = array_column($result['passed'], 'id');
        $this->assertContains('no-flexbox', $passedIds);
        $this->assertContains('no-grid', $passedIds);
        $this->assertContains('no-script', $passedIds);

        $this->assertArrayHasKey('message', $result['passed'][0]);
        $this->assertArrayHasKey('tags', $result['passed'][0]);
    }

    public function test_multi_locale_returns_arrays(): void
    {
        $audit  = new MailAudit([], ['en', 'fr']);
        $result = $audit->analyze('<div style="display: flex;">test</div>');

        $insight = array_values(array_filter($result['insights'], fn($i) => $i['id'] === 'no-flexbox'))[0];

        $this->assertIsArray($insight['message']);
        $this->assertArrayHasKey('en', $insight['message']);
        $this->assertArrayHasKey('fr', $insight['message']);
        $this->assertIsString($insight['message']['en']);
        $this->assertIsString($insight['message']['fr']);
    }

    public function test_transform_does_not_trigger_on_text_transform(): void
    {
        $this->assertRuleNotTriggered(
            '<td style="text-transform: uppercase; font-size: 14px;">Hello</td>',
            'no-transform'
        );
    }

    public function test_css_variables_does_not_trigger_on_html_comments(): void
    {
        $this->assertRuleNotTriggered(
            '<!-- This is a comment --> <td style="color: red;">Hello</td>',
            'no-css-variables'
        );
    }

    public function test_score_is_between_0_and_100(): void
    {
        $html = '<div style="display:flex;"><svg></svg><form><input></form></div>';

        $result = $this->audit->analyze($html);

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    // --- CSS property rules ---

    public function test_flexbox_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<div style="display: flex;">content</div>',
            'no-flexbox',
            'error'
        );
    }

    public function test_grid_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<div style="display: grid;">content</div>',
            'no-grid',
            'error'
        );
    }

    public function test_css_variables_trigger_warning(): void
    {
        $this->assertRuleTriggered(
            '<td style="color: var(--brand-color);">text</td>',
            'no-css-variables',
            'warning'
        );
    }

    public function test_float_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<div style="float: left;">text</div>',
            'no-float',
            'warning'
        );
    }

    public function test_position_absolute_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<div style="position: absolute; top: 0;">text</div>',
            'no-position-absolute',
            'info'
        );
    }

    public function test_border_radius_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<td style="border-radius: 8px;">text</td>',
            'no-border-radius',
            'info'
        );
    }

    public function test_box_shadow_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<td style="box-shadow: 0 2px 4px #000;">text</td>',
            'no-box-shadow',
            'info'
        );
    }

    // --- HTML content rules ---

    public function test_embedded_style_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<html><head><style>.foo { color: red; }</style></head><body></body></html>',
            'inline-css',
            'warning'
        );
    }

    public function test_external_font_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet">',
            'no-external-fonts',
            'warning'
        );
    }

    // --- HTML tag rules (DOM-based) ---

    public function test_layout_div_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<div style="width: 600px; max-width: 100%;">layout content</div>',
            'no-div-layout',
            'warning'
        );
    }

    public function test_plain_div_does_not_trigger_layout_warning(): void
    {
        $this->assertRuleNotTriggered(
            '<div>content only</div>',
            'no-div-layout'
        );
    }

    public function test_content_div_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<div>content only</div>',
            'div-content',
            'info'
        );
    }

    public function test_svg_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<table><tr><td><svg width="20" height="20"><circle r="10"/></svg></td></tr></table>',
            'no-svg',
            'error'
        );
    }

    public function test_form_element_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<form action="/subscribe"><input type="email"><button>Submit</button></form>',
            'no-form-elements',
            'error'
        );
    }

    // --- HTML attribute missing rules (DOM-based) ---

    public function test_img_without_dimensions_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<img src="banner.png" alt="Banner">',
            'img-dimensions',
            'warning'
        );
    }

    public function test_img_with_dimensions_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<img src="banner.png" alt="Banner" width="600" height="200">',
            'img-dimensions'
        );
    }

    public function test_img_without_alt_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<img src="banner.png" width="600" height="200">',
            'missing-alt-img',
            'warning'
        );
    }

    public function test_img_with_alt_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<img src="banner.png" alt="" width="600" height="200">',
            'missing-alt-img'
        );
    }

    public function test_table_without_role_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<table><tr><td>content</td></tr></table>',
            'table-role-presentation',
            'info'
        );
    }

    public function test_table_with_role_presentation_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<table role="presentation"><tr><td>content</td></tr></table>',
            'table-role-presentation'
        );
    }

    // --- Style block rules ---

    public function test_class_selector_in_style_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<style>.wrapper { display: block; }</style><div class="wrapper">x</div>',
            'css-class-selectors',
            'warning'
        );
    }

    public function test_element_selector_in_style_does_not_trigger_class_rule(): void
    {
        $this->assertRuleNotTriggered(
            '<style>td { color: red; }</style>',
            'css-class-selectors'
        );
    }

    public function test_pseudo_selector_in_style_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<style>a:hover { color: red; }</style>',
            'css-pseudo-selectors',
            'info'
        );
    }

    public function test_media_query_in_style_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<style>@media (max-width: 600px) { td { display: block; } }</style>',
            'css-media-queries',
            'warning'
        );
    }

    public function test_at_import_in_style_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style>',
            'css-at-import',
            'error'
        );
    }

    public function test_style_block_rules_silent_without_style_tag(): void
    {
        $html = '<table role="presentation"><tr><td style="font-family: Arial;">Hello</td></tr></table>';

        $this->assertRuleNotTriggered($html, 'css-class-selectors');
        $this->assertRuleNotTriggered($html, 'css-pseudo-selectors');
        $this->assertRuleNotTriggered($html, 'css-media-queries');
        $this->assertRuleNotTriggered($html, 'css-at-import');
    }

    // --- Multiple issues compound the score ---

    public function test_multiple_issues_reduce_score(): void
    {
        $html = '<div style="display: flex; display: grid;">
                    <img src="x.png">
                    <svg></svg>
                 </div>';

        $result = $this->audit->analyze($html);

        $this->assertLessThan(60, $result['score']);
        $this->assertGreaterThan(0, $result['summary']['errors']);
    }

    // --- Helpers ---

    private function assertRuleTriggered(string $html, string $ruleId, string $severity): void
    {
        $result = $this->audit->analyze($html);
        $ids    = array_column($result['insights'], 'id');

        $this->assertContains($ruleId, $ids, "Expected rule '{$ruleId}' to be triggered.");

        $insight = array_values(array_filter($result['insights'], fn($i) => $i['id'] === $ruleId))[0];
        $this->assertSame($severity, $insight['severity'], "Expected rule '{$ruleId}' severity to be '{$severity}'.");
    }

    private function assertRuleNotTriggered(string $html, string $ruleId): void
    {
        $result = $this->audit->analyze($html);
        $ids    = array_column($result['insights'], 'id');

        $this->assertNotContains($ruleId, $ids, "Expected rule '{$ruleId}' NOT to be triggered.");
    }
}
