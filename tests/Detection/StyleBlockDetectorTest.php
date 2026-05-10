<?php

namespace MailAudit\Tests\Detection;

use MailAudit\Detection\StyleBlockDetector;
use PHPUnit\Framework\TestCase;

class StyleBlockDetectorTest extends TestCase
{
    private StyleBlockDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new StyleBlockDetector();
    }

    // --- class selectors ---

    public function test_fires_on_class_selector_in_style_block(): void
    {
        $html = '<style>.wrapper { color: red; }</style><div class="wrapper">x</div>';

        $this->assertTrue($this->detector->matches($html, [
            'regex'    => true,
            'patterns' => ['\\.([a-zA-Z_-][\\w-]*)\\s*[{,:\\[]'],
        ]));
    }

    public function test_silent_when_no_style_block(): void
    {
        $html = '<div style="color: red;">x</div>';

        $this->assertFalse($this->detector->matches($html, [
            'regex'    => true,
            'patterns' => ['\\.([a-zA-Z_-][\\w-]*)\\s*[{,:\\[]'],
        ]));
    }

    public function test_silent_when_style_block_has_no_class_selector(): void
    {
        $html = '<style>td { color: red; }</style><td>x</td>';

        $this->assertFalse($this->detector->matches($html, [
            'regex'    => true,
            'patterns' => ['\\.([a-zA-Z_-][\\w-]*)\\s*[{,:\\[]'],
        ]));
    }

    // --- pseudo-selectors ---

    public function test_fires_on_hover_in_style_block(): void
    {
        $html = '<style>a:hover { text-decoration: underline; }</style>';

        $this->assertTrue($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => [':hover'],
        ]));
    }

    public function test_silent_when_hover_is_in_inline_style_not_style_block(): void
    {
        // Inline styles cannot contain :hover — this tests that we don't false-positive
        $html = '<a style="color: blue;">link</a>';

        $this->assertFalse($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => [':hover'],
        ]));
    }

    // --- media queries ---

    public function test_fires_on_media_query_in_style_block(): void
    {
        $html = '<style>@media (max-width: 600px) { .col { width: 100%; } }</style>';

        $this->assertTrue($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => ['@media'],
        ]));
    }

    public function test_silent_when_no_media_query(): void
    {
        $html = '<style>td { font-size: 14px; }</style>';

        $this->assertFalse($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => ['@media'],
        ]));
    }

    // --- @import ---

    public function test_fires_on_at_import_in_style_block(): void
    {
        $html = '<style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style>';

        $this->assertTrue($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => ['@import'],
        ]));
    }

    public function test_silent_when_no_at_import(): void
    {
        $html = '<style>td { font-family: Arial, sans-serif; }</style>';

        $this->assertFalse($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => ['@import'],
        ]));
    }

    // --- multiple style blocks ---

    public function test_scans_all_style_blocks(): void
    {
        $html = '<style>td { color: red; }</style>'
              . '<style>@media (max-width: 600px) { td { display: block; } }</style>';

        $this->assertTrue($this->detector->matches($html, [
            'regex'    => false,
            'patterns' => ['@media'],
        ]));
    }
}
