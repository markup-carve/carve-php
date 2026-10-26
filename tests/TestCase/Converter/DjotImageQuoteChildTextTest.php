<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotImageQuoteChildTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controls(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-image-quote-child-text.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $n => $row) {
            yield 'quote-' . $n => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('controls')]
    public function testNativeImageChildText(string $source, string $expected): void
    {
        $converted = (new DjotToCarve())->convert($source);
        $actual = (new CarveConverter())->convert($converted);
        self::assertSame($this->shape(ImageParagraphFixture::normalized($expected)), $this->shape($actual), $source . "\n" . $converted);
    }

    private function shape(string $html): mixed
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $body = $document->getElementsByTagName('body')->item(0);
        self::assertNotNull($body);

        return $this->nodeShape($body);
    }

    private function nodeShape(DOMNode $node): mixed
    {
        if ($node instanceof DOMText) {
            $literalCode = false;
            for ($ancestor = $node->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                if (in_array($ancestor->nodeName, ['code', 'pre'], true)) {
                    $literalCode = true;

                    break;
                }
            }
            $value = $literalCode ? $node->data : (preg_replace('/[\t\r\n ]+/', ' ', $node->data) ?? $node->data);
            if ($node->parentNode !== null && in_array($node->parentNode->nodeName, ['p', 'li', 'blockquote', 'td', 'th'], true)) {
                if ($node->previousSibling === null) {
                    $value = ltrim($value, ' ');
                }
                if ($node->nextSibling === null) {
                    $value = rtrim($value, ' ');
                }
            }

            return $value;
        }
        $attrs = [];
        if ($node instanceof DOMElement) {
            foreach ($node->attributes as $attr) {
                if ($attr->name !== 'data-delim') {
                    $attrs[$attr->name] = $attr->value;
                }
            }
            ksort($attrs);
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->data, "\t\r\n ") === '' && in_array($node->nodeName, ['body', 'blockquote', 'ul', 'ol', 'li'], true)) {
                continue;
            }
            $children[] = $this->nodeShape($child);
        }

        return [$node->nodeName, $attrs, $children];
    }
}
