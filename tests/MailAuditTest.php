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
        $html = '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="color:red;">Hello</td></tr></table>';

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

    public function test_css_variables_without_fallback_triggers_error(): void
    {
        $this->assertRuleTriggered(
            '<td style="color: var(--brand-color);">text</td>',
            'no-css-variables',
            'error'
        );
    }

    public function test_css_variables_with_fallback_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<td style="color: var(--brand-color, #ff0000);">text</td>',
            'no-css-variables'
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

    public function test_embedded_style_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<html><head><style>.foo { color: red; }</style></head><body></body></html>',
            'inline-css',
            'info'
        );
    }

    public function test_external_font_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet">',
            'no-external-fonts',
            'info'
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

    public function test_class_selector_in_style_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<style>.wrapper { display: block; }</style><div class="wrapper">x</div>',
            'css-class-selectors',
            'info'
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

    public function test_media_query_in_style_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<style>@media (max-width: 600px) { td { display: block; } }</style>',
            'css-media-queries',
            'info'
        );
    }

    public function test_at_import_in_style_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style>',
            'css-at-import',
            'info'
        );
    }

    public function test_at_import_without_link_triggers_warning(): void
    {
        $html = '<style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style><p style="font-family:Roboto,Arial;">Hello</p>';
        $this->assertRuleTriggered($html, 'css-at-import-no-link', 'warning');
    }

    public function test_at_import_with_link_does_not_trigger_warning(): void
    {
        $html = '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto"><style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style><p style="font-family:Roboto,Arial;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'css-at-import-no-link');
    }

    public function test_style_block_rules_silent_without_style_tag(): void
    {
        $html = '<table role="presentation"><tr><td style="font-family: Arial;">Hello</td></tr></table>';

        $this->assertRuleNotTriggered($html, 'css-class-selectors');
        $this->assertRuleNotTriggered($html, 'css-pseudo-selectors');
        $this->assertRuleNotTriggered($html, 'css-media-queries');
        $this->assertRuleNotTriggered($html, 'css-at-import');
    }

    // --- Correlation rules (fallback quality) ---

    public function test_style_block_without_inline_triggers_fallback_warning(): void
    {
        $this->assertRuleTriggered(
            '<html><head><style>.title { font-size: 24px; }</style></head><body><p class="title">Hello</p></body></html>',
            'style-no-inline-fallback',
            'warning'
        );
    }

    public function test_style_block_with_inline_does_not_trigger_fallback_rule(): void
    {
        $this->assertRuleNotTriggered(
            '<html><head><style>.title { font-size: 24px; }</style></head><body><p class="title" style="font-size: 24px;">Hello</p></body></html>',
            'style-no-inline-fallback'
        );
    }

    public function test_external_font_without_fallback_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet"><td>Hello</td>',
            'font-no-fallback',
            'warning'
        );
    }

    public function test_external_font_with_inline_fallback_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<link href="https://fonts.googleapis.com/css?family=Roboto" rel="stylesheet"><td style="font-family: Roboto, Arial, sans-serif;">Hello</td>',
            'font-no-fallback'
        );
    }

    public function test_font_no_fallback_does_not_fire_with_quoted_first_font(): void
    {
        $html = '<style>@font-face{font-family:"Kia";src:url(x.woff);}</style>'
              . '<p style="font-family:\'Kia Signature Regular\', \'Open Sans\', Arial, \'Helvetica Neue\', Helvetica, sans-serif;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'font-no-fallback');
    }

    public function test_media_query_without_inline_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<html><head><style>@media (max-width: 600px) { td { display: block; } }</style></head><body><table><tr><td>Hello</td></tr></table></body></html>',
            'media-no-inline-base',
            'warning'
        );
    }

    public function test_media_query_with_inline_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<html><head><style>@media (max-width: 600px) { td { display: block; } }</style></head><body><table><tr><td style="display: table-cell;">Hello</td></tr></table></body></html>',
            'media-no-inline-base'
        );
    }

    // --- New quality / structure rules ---

    public function test_empty_alt_triggers_info(): void
    {
        $this->assertRuleTriggered(
            '<img src="banner.png" alt="" width="600" height="200">',
            'empty-alt-img',
            'info'
        );
    }

    public function test_descriptive_alt_does_not_trigger_empty_alt(): void
    {
        $this->assertRuleNotTriggered(
            '<img src="banner.png" alt="Product banner" width="600" height="200">',
            'empty-alt-img'
        );
    }

    public function test_missing_lang_triggers_on_full_document(): void
    {
        $this->assertRuleTriggered(
            '<html><head><title>Test</title></head><body><p>Hello</p></body></html>',
            'missing-lang',
            'info'
        );
    }

    public function test_present_lang_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<html lang="en"><head></head><body><p>Hello</p></body></html>',
            'missing-lang'
        );
    }

    public function test_missing_lang_silent_on_fragment(): void
    {
        $this->assertRuleNotTriggered(
            '<table><tr><td>Hello</td></tr></table>',
            'missing-lang'
        );
    }

    public function test_missing_viewport_triggers_on_full_document(): void
    {
        $this->assertRuleTriggered(
            '<html><head><title>Test</title></head><body><p>Hello</p></body></html>',
            'missing-viewport',
            'info'
        );
    }

    public function test_present_viewport_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><p>Hello</p></body></html>',
            'missing-viewport'
        );
    }

    public function test_http_image_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<img src="http://example.com/image.jpg" alt="Test" width="600" height="200">',
            'missing-https',
            'warning'
        );
    }

    public function test_https_image_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<img src="https://example.com/image.jpg" alt="Test" width="600" height="200">',
            'missing-https'
        );
    }

    public function test_nbsp_missing_triggers_on_currency_space(): void
    {
        $this->assertRuleTriggered(
            '<td>Price: 100 €</td>',
            'nbsp-missing',
            'info'
        );
    }

    public function test_nbsp_encoded_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<td>Price: 100&nbsp;€</td>',
            'nbsp-missing'
        );
    }

    public function test_url_with_space_triggers_warning(): void
    {
        $this->assertRuleTriggered(
            '<a href="https://example.com/my page.html">link</a>',
            'url-unencoded',
            'warning'
        );
    }

    public function test_url_without_space_does_not_trigger(): void
    {
        $this->assertRuleNotTriggered(
            '<a href="https://example.com/my-page.html">link</a>',
            'url-unencoded'
        );
    }

    public function test_preheader_missing_triggers_on_full_document(): void
    {
        $this->assertRuleTriggered(
            '<html><head></head><body><table><tr><td>Hello</td></tr></table></body></html>',
            'preheader-missing',
            'info'
        );
    }

    public function test_preheader_missing_silent_on_fragment(): void
    {
        $this->assertRuleNotTriggered(
            '<table><tr><td>Hello</td></tr></table>',
            'preheader-missing'
        );
    }

    public function test_preheader_present_does_not_trigger_missing(): void
    {
        $html = '<html><body><div style="display:none;font-size:1px;max-height:0px;overflow:hidden;">Preview text &nbsp;&zwnj;</div><table><tr><td>Hello</td></tr></table></body></html>';
        $this->assertRuleNotTriggered($html, 'preheader-missing');
    }

    public function test_preheader_too_long_triggers(): void
    {
        $longText = str_repeat('A', 160);
        $html = '<html><body><div style="display:none;overflow:hidden;">' . $longText . '</div><p>Body</p></body></html>';
        $this->assertRuleTriggered($html, 'preheader-too-long', 'info');
    }

    public function test_preheader_filling_not_counted_in_length(): void
    {
        $filling = str_repeat('&nbsp;&zwnj;', 100);
        $html = '<html><body><div style="display:none;overflow:hidden;">Short preview ' . $filling . '</div><p>Body</p></body></html>';
        $this->assertRuleNotTriggered($html, 'preheader-too-long');
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

    // --- heading-order ---

    public function test_heading_skip_triggers_info(): void
    {
        $html = '<h1>Title</h1><h3>Section</h3>';
        $this->assertRuleTriggered($html, 'heading-order', 'info');
    }

    public function test_heading_skip_two_levels_triggers_info(): void
    {
        $html = '<h1>Title</h1><h4>Sub</h4>';
        $this->assertRuleTriggered($html, 'heading-order', 'info');
    }

    public function test_sequential_headings_do_not_trigger(): void
    {
        $html = '<h1>Title</h1><h2>Section</h2><h3>Sub</h3>';
        $this->assertRuleNotTriggered($html, 'heading-order');
    }

    public function test_heading_can_jump_down_levels(): void
    {
        // Jumping back to a higher heading (lower number) is fine
        $html = '<h1>Title</h1><h2>Section</h2><h1>Another Title</h1>';
        $this->assertRuleNotTriggered($html, 'heading-order');
    }

    public function test_heading_order_location_points_to_skipped_tag(): void
    {
        $html = '<h1>Title</h1><h3>Skipped</h3>';
        $result = $this->audit->analyze($html);
        $insight = array_values(array_filter($result['insights'], fn($i) => $i['id'] === 'heading-order'))[0];

        $this->assertNotEmpty($insight['locations']);
        $snippet = substr($html, $insight['locations'][0]['offset_start'], $insight['locations'][0]['offset_end'] - $insight['locations'][0]['offset_start']);
        $this->assertStringContainsString('h3', strtolower($snippet));
    }

    // --- link-no-text ---

    public function test_link_completely_empty_triggers_warning(): void
    {
        $html = '<a href="https://example.com"></a>';
        $this->assertRuleTriggered($html, 'link-no-text', 'warning');
    }

    public function test_link_whitespace_only_triggers_warning(): void
    {
        $html = '<a href="https://example.com">   </a>';
        $this->assertRuleTriggered($html, 'link-no-text', 'warning');
    }

    public function test_link_with_text_does_not_trigger(): void
    {
        $html = '<a href="https://example.com">Click here</a>';
        $this->assertRuleNotTriggered($html, 'link-no-text');
    }

    public function test_link_with_image_empty_alt_does_not_trigger_link_no_text(): void
    {
        // Image with empty alt is handled by empty-alt-img, not link-no-text
        $html = '<a href="https://example.com"><img src="img.jpg" alt=""></a>';
        $this->assertRuleNotTriggered($html, 'link-no-text');
    }

    public function test_link_with_image_and_alt_does_not_trigger(): void
    {
        $html = '<a href="https://example.com"><img src="img.jpg" alt="Go to homepage"></a>';
        $this->assertRuleNotTriggered($html, 'link-no-text');
    }

    public function test_link_with_image_no_alt_attr_does_not_trigger_link_no_text(): void
    {
        // An img tag (even without alt) is a child element — not our concern here
        $html = '<a href="https://example.com"><img src="img.jpg"></a>';
        $this->assertRuleNotTriggered($html, 'link-no-text');
    }

    // --- tracking-pixel ---

    public function test_tracking_pixel_triggers_info(): void
    {
        $html = '<img src="https://track.example.com/open.gif" width="1" height="1" alt="">';
        $this->assertRuleTriggered($html, 'tracking-pixel', 'info');
    }

    public function test_tracking_pixel_with_style_triggers_info(): void
    {
        $html = '<img src="https://track.example.com/open.gif" alt="" style="width:1px;height:1px;">';
        $this->assertRuleTriggered($html, 'tracking-pixel', 'info');
    }

    public function test_regular_image_does_not_trigger_tracking_pixel(): void
    {
        $html = '<img src="https://example.com/banner.jpg" width="600" height="200" alt="Banner">';
        $this->assertRuleNotTriggered($html, 'tracking-pixel');
    }

    public function test_tracking_pixel_has_zero_weight_no_score_impact(): void
    {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="color:red;">Hello</td></tr></table>'
            . '<img src="https://track.example.com/open.gif" width="1" height="1" alt="">';
        $result = $this->audit->analyze($html);

        $this->assertSame(100, $result['score'], 'Tracking pixel with weight=0 should not reduce score');
        $ids = array_column($result['insights'], 'id');
        $this->assertContains('tracking-pixel', $ids, 'Tracking pixel should still appear in insights');
    }

    // --- font-family-unquoted ---

    public function test_unquoted_multiword_font_triggers_info(): void
    {
        $html = '<p style="font-family: Open Sans, Arial, sans-serif;">Hello</p>';
        $this->assertRuleTriggered($html, 'font-family-unquoted', 'info');
    }

    public function test_unquoted_multiword_font_in_style_block_triggers_info(): void
    {
        $html = '<style>p { font-family: Times New Roman, serif; }</style><p style="font-family: Times New Roman, serif;">Hello</p>';
        $this->assertRuleTriggered($html, 'font-family-unquoted', 'info');
    }

    public function test_quoted_multiword_font_does_not_trigger(): void
    {
        $html = '<p style="font-family: \'Open Sans\', Arial, sans-serif;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'font-family-unquoted');
    }

    public function test_double_quoted_multiword_font_does_not_trigger(): void
    {
        $html = '<p style="font-family: &quot;Open Sans&quot;, Arial, sans-serif;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'font-family-unquoted');
    }

    public function test_single_word_fonts_do_not_trigger(): void
    {
        $html = '<p style="font-family: Arial, Helvetica, sans-serif;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'font-family-unquoted');
    }

    public function test_generic_families_do_not_trigger(): void
    {
        $html = '<p style="font-family: serif;">Hello</p>';
        $this->assertRuleNotTriggered($html, 'font-family-unquoted');
    }

    // --- missing-charset ---

    public function test_missing_charset_triggers_on_head_without_meta(): void
    {
        $html = '<html><head><title>Email</title></head><body><p>Hello</p></body></html>';
        $this->assertRuleTriggered($html, 'missing-charset', 'info');
    }

    public function test_missing_charset_does_not_fire_on_fragment(): void
    {
        $html = '<table><tr><td>Hello</td></tr></table>';
        $this->assertRuleNotTriggered($html, 'missing-charset');
    }

    public function test_charset_declared_does_not_trigger(): void
    {
        $html = '<html><head><meta charset="UTF-8"></head><body><p>Hello</p></body></html>';
        $this->assertRuleNotTriggered($html, 'missing-charset');
    }

    // --- missing-doctype ---

    public function test_missing_doctype_triggers_on_html_without_declaration(): void
    {
        $html = '<html lang="en"><head></head><body><p>Hello</p></body></html>';
        $this->assertRuleTriggered($html, 'missing-doctype', 'info');
    }

    public function test_missing_doctype_does_not_fire_on_fragment(): void
    {
        $html = '<p>Hello world</p>';
        $this->assertRuleNotTriggered($html, 'missing-doctype');
    }

    public function test_doctype_present_does_not_trigger(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head></head><body><p>Hello</p></body></html>';
        $this->assertRuleNotTriggered($html, 'missing-doctype');
    }

    // --- table-cellspacing ---

    public function test_table_without_cellpadding_triggers(): void
    {
        $html = '<table width="600"><tr><td>Hello</td></tr></table>';
        $this->assertRuleTriggered($html, 'table-cellspacing', 'info');
    }

    public function test_table_with_cellpadding_cellspacing_zero_does_not_trigger(): void
    {
        $html = '<table width="600" cellpadding="0" cellspacing="0"><tr><td>Hello</td></tr></table>';
        $this->assertRuleNotTriggered($html, 'table-cellspacing');
    }

    public function test_table_with_nonzero_cellpadding_triggers(): void
    {
        $html = '<table cellpadding="10" cellspacing="0"><tr><td>Hello</td></tr></table>';
        $this->assertRuleTriggered($html, 'table-cellspacing', 'info');
    }

    // --- email-max-width ---

    public function test_table_wider_than_600_triggers_warning(): void
    {
        $html = '<table width="700"><tr><td>Hello</td></tr></table>';
        $this->assertRuleTriggered($html, 'email-max-width', 'warning');
    }

    public function test_table_exactly_600_does_not_trigger(): void
    {
        $html = '<table width="600"><tr><td>Hello</td></tr></table>';
        $this->assertRuleNotTriggered($html, 'email-max-width');
    }

    public function test_full_width_wrapper_does_not_trigger(): void
    {
        $html = '<table width="100%"><tr><td><table width="600"><tr><td>Hello</td></tr></table></td></tr></table>';
        $this->assertRuleNotTriggered($html, 'email-max-width');
    }

    public function test_table_with_style_width_over_600_triggers(): void
    {
        $html = '<table style="width:800px;"><tr><td>Hello</td></tr></table>';
        $this->assertRuleTriggered($html, 'email-max-width', 'warning');
    }

    // --- missing-body-bgcolor ---

    public function test_body_without_bgcolor_triggers_info(): void
    {
        $html = '<html><body><p>Hello</p></body></html>';
        $this->assertRuleTriggered($html, 'missing-body-bgcolor', 'info');
    }

    public function test_body_with_bgcolor_does_not_trigger(): void
    {
        $html = '<html><body bgcolor="#ffffff"><p>Hello</p></body></html>';
        $this->assertRuleNotTriggered($html, 'missing-body-bgcolor');
    }

    public function test_body_with_inline_background_color_does_not_trigger(): void
    {
        $html = '<html><body style="background-color:#ffffff;"><p>Hello</p></body></html>';
        $this->assertRuleNotTriggered($html, 'missing-body-bgcolor');
    }

    public function test_body_bgcolor_does_not_fire_on_fragment(): void
    {
        $html = '<table><tr><td>Hello</td></tr></table>';
        $this->assertRuleNotTriggered($html, 'missing-body-bgcolor');
    }

    // --- text-image-ratio ---

    public function test_image_heavy_email_triggers_ratio_warning(): void
    {
        $imgs = str_repeat('<img src="https://example.com/x.jpg" width="600" height="300" alt="Banner">', 5);
        $html = '<html lang="en"><body><table role="presentation"><tr><td>' . $imgs . '<p>Hi</p></td></tr></table></body></html>';
        $this->assertRuleTriggered($html, 'text-image-ratio', 'warning');
    }

    public function test_text_rich_email_does_not_trigger_ratio(): void
    {
        $para = str_repeat('<p style="font-family:Arial;">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore.</p>', 3);
        $html = '<html lang="en"><body><table role="presentation"><tr><td>'
            . '<img src="https://example.com/banner.jpg" width="600" height="200" alt="Banner">'
            . $para
            . '</td></tr></table></body></html>';
        $this->assertRuleNotTriggered($html, 'text-image-ratio');
    }

    public function test_text_only_email_does_not_trigger_ratio(): void
    {
        $html = '<html lang="en"><body><p>Hello world, this is a text-only email with plenty of content.</p></body></html>';
        $this->assertRuleNotTriggered($html, 'text-image-ratio');
    }

    public function test_ratio_does_not_fire_on_fragment(): void
    {
        // Fragment (no <body>) — should be skipped
        $imgs = str_repeat('<img src="x.jpg" alt="">', 10);
        $html = '<table><tr><td>' . $imgs . '</td></tr></table>';
        $this->assertRuleNotTriggered($html, 'text-image-ratio');
    }

    // --- Integration: real email scenarios ---

    public function test_well_formed_complete_email_scores_high(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome</title>
</head>
<body>
<div style="display:none;font-size:1px;max-height:0;overflow:hidden;">Preview text &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="font-family:Arial,sans-serif;font-size:16px;color:#333333;padding:20px;">
            <h1 style="font-family:Arial,sans-serif;font-size:24px;color:#000000;">Welcome aboard</h1>
            <h2 style="font-family:Arial,sans-serif;font-size:18px;color:#333333;">Your account is ready</h2>
            <img src="https://example.com/banner.jpg" width="560" height="200" alt="Welcome banner" style="display:block;max-width:100%;">
            <p style="font-family:Arial,sans-serif;font-size:16px;line-height:1.5;color:#333333;">
                Thank you for joining us. Your account has been created and is ready to use.
                Click the button below to get started and explore all the features available to you.
            </p>
            <a href="https://example.com/start" style="display:inline-block;padding:12px 24px;background-color:#007bff;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:16px;border-radius:4px;">Get started</a>
        </td>
    </tr>
</table>
</body>
</html>
HTML;

        $result = (new MailAudit())->analyze($html);

        $this->assertGreaterThanOrEqual(85, $result['score'], 'Well-formed email should score ≥ 85');
        $this->assertSame(0, $result['summary']['errors'], 'Well-formed email should have no errors');
    }

    public function test_poorly_formed_email_has_errors_and_low_score(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body>
<div style="display:flex;width:600px;">
    <img src="http://example.com/banner.jpg">
    <img src="http://example.com/img2.jpg">
    <img src="http://example.com/img3.jpg">
    <p>Hi</p>
</div>
</body>
</html>
HTML;

        $result = (new MailAudit())->analyze($html);

        $this->assertGreaterThan(0, $result['summary']['errors'], 'Should have at least one error');
        $this->assertLessThan(75, $result['score'], 'Poorly formed email should score < 75');
    }

    public function test_complete_email_heading_and_link_rules_fire(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body>
<div style="display:none;overflow:hidden;">Preview &nbsp;&zwnj;</div>
<table role="presentation">
    <tr><td style="font-family:Arial;">
        <h1 style="font-family:Arial;">Title</h1>
        <h3 style="font-family:Arial;">Skipped h2</h3>
        <p style="font-family:Arial;">Some content here for the email body text.</p>
        <a href="https://example.com"><img src="https://example.com/cta.jpg" alt="" width="200" height="60"></a>
    </td></tr>
</table>
</body>
</html>
HTML;

        $result = (new MailAudit())->analyze($html);
        $ids    = array_column($result['insights'], 'id');

        $this->assertContains('heading-order', $ids, 'heading-order should fire on h1→h3 skip');
        $this->assertContains('empty-alt-img', $ids, 'empty-alt-img should fire on image with empty alt inside a link');
    }

    // --- Multiple-whitespace robustness ---

    public function test_double_space_before_style_still_triggers(): void
    {
        // <div  style="display:flex"> — two spaces between tag name and style
        $html = '<div  style="display:flex;">test</div>';
        $this->assertRuleTriggered($html, 'no-flexbox', 'error');
    }

    public function test_double_space_in_img_with_descriptive_alt_does_not_trigger_link_no_text(): void
    {
        $html = '<a  href="https://example.com"><img  src="img.jpg" alt="Company logo"></a>';
        $this->assertRuleNotTriggered($html, 'link-no-text');
    }

    public function test_double_space_preheader_detected(): void
    {
        $html = '<body><div  style="display:none;overflow:hidden;">Preview text</div><p>Body</p></body>';
        // preheader-missing must NOT fire when preheader is present with double-space attributes
        $this->assertRuleNotTriggered($html, 'preheader-missing');
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
