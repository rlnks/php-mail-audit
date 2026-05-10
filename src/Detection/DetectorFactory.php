<?php

namespace MailAudit\Detection;

class DetectorFactory
{
    private static array $registry = [
        'css_property'           => CssPropertyDetector::class,
        'html_tag'               => HtmlTagDetector::class,
        'html_tag_with_style'    => HtmlTagWithStyleDetector::class,
        'html_attribute_missing' => HtmlAttributeMissingDetector::class,
        'html_content'           => HtmlContentDetector::class,
        'style_block'            => StyleBlockDetector::class,
    ];

    public static function make(string $type): DetectorInterface
    {
        if (!isset(self::$registry[$type])) {
            throw new \InvalidArgumentException("Unknown detection type: {$type}");
        }

        return new self::$registry[$type]();
    }

    public static function register(string $type, string $class): void
    {
        self::$registry[$type] = $class;
    }
}
