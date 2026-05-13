<?php

namespace MailAudit\Detection;

/**
 * Fires when at least one instance of `tag` is missing any of the required `attributes`,
 * or has an attribute that does not match the expected `attribute_value`.
 *
 * Rule JSON shape:
 *   "detection": { "type": "html_attribute_missing", "tag": "img", "attributes": ["width", "height"] }
 *   "detection": { "type": "html_attribute_missing", "tag": "table", "attributes": ["role"], "attribute_value": "presentation" }
 */
class HtmlAttributeMissingDetector extends AbstractDetector
{
    public function findMatches(string $html, array $detection): array
    {
        $tag        = $detection['tag'] ?? null;
        $attributes = $detection['attributes'] ?? [];
        $value      = $detection['attribute_value'] ?? null;
        $locations  = [];

        if (!$tag || empty($attributes)) {
            return $locations;
        }

        $regex = '/<' . preg_quote($tag, '/') . '[^>]*>/si';

        if (!preg_match_all($regex, $html, $m, PREG_OFFSET_CAPTURE)) {
            return $locations;
        }

        $onlyEmpty = $detection['only_empty'] ?? false;

        foreach ($m[0] as [$tagHtml, $offset]) {
            $fires = $onlyEmpty
                ? $this->isPresentButEmpty($tagHtml, $attributes)
                : $this->isMissingAttribute($tagHtml, $attributes, $value);

            if ($fires) {
                $locations[] = $this->buildLocation($html, $offset, strlen($tagHtml));
            }
        }

        return $locations;
    }

    private function isMissingAttribute(string $tagHtml, array $attributes, ?string $value): bool
    {
        foreach ($attributes as $attr) {
            if (!preg_match('/\b' . preg_quote($attr, '/') . '\b/i', $tagHtml)) {
                return true;
            }

            if ($value !== null) {
                $valuePattern = '/\b' . preg_quote($attr, '/') . '\s*=\s*["\']?' . preg_quote($value, '/') . '["\']?/i';
                if (!preg_match($valuePattern, $tagHtml)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Fires only when the attribute IS present but its value is empty. */
    private function isPresentButEmpty(string $tagHtml, array $attributes): bool
    {
        foreach ($attributes as $attr) {
            $pattern = '/\b' . preg_quote($attr, '/') . '\s*=\s*(?:""|\'\')/i';
            if (preg_match($pattern, $tagHtml)) {
                return true;
            }
        }
        return false;
    }
}
