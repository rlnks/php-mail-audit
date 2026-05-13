<?php

namespace MailAudit\Detection;

/**
 * Detects the standard email preheader pattern (a hidden div used to control preview text).
 *
 * Modes:
 *  - "missing"  : fires when no preheader div is found
 *  - "too_long" : fires when the clean preheader text exceeds max_length characters
 *                 (filler characters — &nbsp; &zwnj; and Unicode equivalents — are excluded from the count)
 */
class PreheaderDetector extends AbstractDetector
{
    private const FILLER_ENTITIES = ['&nbsp;', '&zwnj;', '&#847;', '&#8203;', '&#65279;', '&shy;', '&#173;', '&#x200c;', '&#x200b;'];

    /**
     * @return list<array{line: int, column: int, offset_start: int, offset_end: int}>
     */
    public function findMatches(string $html, array $detection): array
    {
        $mode      = $detection['mode']       ?? 'missing';
        $maxLength = $detection['max_length'] ?? 150;

        $preheader = $this->findPreheader($html);

        if ($mode === 'missing') {
            // Only flag complete documents (those with a <body> tag) — skip fragments
            if (stripos($html, '<body') === false) {
                return [];
            }
            return $preheader === null
                ? [['line' => 1, 'column' => 1, 'offset_start' => 0, 'offset_end' => 0]]
                : [];
        }

        if ($mode === 'too_long') {
            if ($preheader === null) {
                return [];
            }
            $clean = $this->stripFilling($preheader['content']);
            if (mb_strlen($clean) > $maxLength) {
                return [$this->buildLocation($html, $preheader['offset'], $preheader['length'])];
            }
            return [];
        }

        return [];
    }

    /** @return array{content:string, offset:int, length:int}|null */
    private function findPreheader(string $html): ?array
    {
        // Step 1: find all <div> opening tags — [^>]* handles any number of spaces between attributes
        if (!preg_match_all('/<div\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($tags[0] as [$openTag, $tagOffset]) {
            // Step 2: extract style value (\s before "style" prevents matching data-style)
            if (!preg_match('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $openTag, $sm)) {
                continue;
            }
            $style = $sm[1] !== '' ? $sm[1] : $sm[2];

            $hasNone   = (bool) preg_match('/display\s*:\s*none/i', $style);
            $hasHidden = (bool) preg_match('/overflow\s*:\s*hidden/i', $style);

            if (!$hasNone || !$hasHidden) {
                continue;
            }

            // Find the matching closing </div>
            $closePos = stripos($html, '</div>', $tagOffset + strlen($openTag));
            if ($closePos === false) {
                continue;
            }

            $content = substr($html, $tagOffset + strlen($openTag), $closePos - $tagOffset - strlen($openTag));
            $total   = $closePos + strlen('</div>') - $tagOffset;

            return ['content' => $content, 'offset' => $tagOffset, 'length' => $total];
        }

        return null;
    }

    private function stripFilling(string $content): string
    {
        $text = strip_tags($content);
        $text = str_ireplace(self::FILLER_ENTITIES, '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Remove Unicode filler: non-breaking space, ZWNJ, ZWS, soft hyphen, word joiner, BOM
        $text = preg_replace('/[\x{00A0}\x{200C}\x{200B}\x{00AD}\x{2060}\x{FEFF}]/u', '', $text);
        return trim($text);
    }
}
