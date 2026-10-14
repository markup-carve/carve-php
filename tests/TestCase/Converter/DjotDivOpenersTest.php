<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotDivOpenersTest extends TestCase
{
    private function content(DOMNode $node, string $parent = ''): mixed
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            if (in_array($parent, ['body', 'div', 'blockquote', 'section', 'ul', 'ol', 'li'], true) && preg_match('/^\s*$/', $node->nodeValue ?? '') === 1) {
                return null;
            }

            return ['text', $node->nodeValue];
        }
        $attributes = [];
        if ($node instanceof DOMElement) {
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            ksort($attributes);
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $value = $this->content($child, $node->nodeName);
            if ($value !== null) {
                $children[] = $value;
            }
        }

        return [$node->nodeName, $attributes, $children];
    }

    private function html(string $html): mixed
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . str_replace(["\r\n", "\r"], "\n", $html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->content($document->getElementsByTagName('body')->item(0));
    }

    public function testCompactAndTabSeparatedDivOpenersMatchNativeDjot(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-div-openers.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $converted = $converter->convert($row['source']);
            $this->assertSame($this->html($row['html']), $this->html($renderer->convert($converted)), $row['source']);
        }
    }
}
