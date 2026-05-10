<?php

namespace MailAudit\Tests\Detection;

use MailAudit\Detection\HtmlAttributeMissingDetector;
use PHPUnit\Framework\TestCase;

class HtmlAttributeMissingDetectorTest extends TestCase
{
    private HtmlAttributeMissingDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new HtmlAttributeMissingDetector();
    }

    public function test_fires_when_img_missing_width(): void
    {
        $this->assertTrue($this->detector->matches(
            '<img src="x.png" alt="x" height="100">',
            ['tag' => 'img', 'attributes' => ['width', 'height']]
        ));
    }

    public function test_fires_when_img_missing_height(): void
    {
        $this->assertTrue($this->detector->matches(
            '<img src="x.png" alt="x" width="100">',
            ['tag' => 'img', 'attributes' => ['width', 'height']]
        ));
    }

    public function test_silent_when_img_has_both_dimensions(): void
    {
        $this->assertFalse($this->detector->matches(
            '<img src="x.png" alt="x" width="100" height="50">',
            ['tag' => 'img', 'attributes' => ['width', 'height']]
        ));
    }

    public function test_fires_when_img_missing_alt(): void
    {
        $this->assertTrue($this->detector->matches(
            '<img src="x.png" width="100" height="50">',
            ['tag' => 'img', 'attributes' => ['alt']]
        ));
    }

    public function test_silent_when_img_has_empty_alt(): void
    {
        $this->assertFalse($this->detector->matches(
            '<img src="x.png" alt="" width="100" height="50">',
            ['tag' => 'img', 'attributes' => ['alt']]
        ));
    }

    public function test_fires_when_table_role_is_not_presentation(): void
    {
        $this->assertTrue($this->detector->matches(
            '<table><tr><td>x</td></tr></table>',
            ['tag' => 'table', 'attributes' => ['role'], 'attribute_value' => 'presentation']
        ));
    }

    public function test_silent_when_table_has_role_presentation(): void
    {
        $this->assertFalse($this->detector->matches(
            '<table role="presentation"><tr><td>x</td></tr></table>',
            ['tag' => 'table', 'attributes' => ['role'], 'attribute_value' => 'presentation']
        ));
    }

    public function test_silent_when_no_matching_tag_in_html(): void
    {
        $this->assertFalse($this->detector->matches(
            '<p>No images here</p>',
            ['tag' => 'img', 'attributes' => ['alt']]
        ));
    }
}
