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

class DjotCollapsedImageKeysTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function controls(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-collapsed-image-keys.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $n => $row) {
            yield 'quote-' . $n => [$row['source'], $row['html'], $row['loss'], $row['blankAlt']];
        }
    }

    #[DataProvider('controls')]
    public function testNativeImageChildText(string $source, string $expected, bool $loss, bool $blankAlt): void
    {
        $report = (new DjotToCarve())->convertWithFidelityReport($source);
        $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $report->diagnostics);
        self::assertSame($loss, in_array('An unresolved Djot image reference has no src; Carve cannot spell that image.', $messages, true), $source);
        self::assertSame($blankAlt, in_array('An image alternative text with a blank line cannot be spelled in Carve.', $messages, true), $source);
        if ($loss) {
            preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/', (new CarveConverter())->convert($report->value), $actualDestinations);
            preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/', $expected, $expectedDestinations);
            self::assertSame($expectedDestinations[1], $actualDestinations[1], $source);
        }
        if ($loss || $blankAlt) {
            return;
        }
        $actual = (new CarveConverter())->convert($report->value);
        self::assertSame($this->shape(ImageParagraphFixture::normalized($expected)), $this->shape($actual), $source . "\n" . $report->value);
    }

    public function testEscapedCollapsedBracketsRemainLiteral(): void
    {
        $report = (new DjotToCarve())->convertWithFidelityReport('\\[a][]' . "\n\n[a]: img");
        self::assertSame('<p>[a][]</p>', trim((new CarveConverter())->convert($report->value)));
    }

    public function testMultilineKeysInsideListQuotes(): void
    {
        foreach (["- > ![a\n  > b][]\n\n[a b]: img", "> - ![a\n>   b][]\n\n[a b]: img"] as $source) {
            $report = (new DjotToCarve())->convertWithFidelityReport($source);
            self::assertSame([], array_filter($report->diagnostics, static fn ($diagnostic): bool => $diagnostic->message === 'An unresolved Djot image reference has no src; Carve cannot spell that image.'));
            $html = (new CarveConverter())->convert($report->value);
            self::assertStringContainsString('alt="a' . "\n" . 'b"', $html);
            self::assertStringContainsString('src="img"', $html);
        }
        $report = (new DjotToCarve())->convertWithFidelityReport("- > ![a\n  > {.x}\n  > b](img)");
        $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $report->diagnostics);
        self::assertContains('An image alternative text with a blank line cannot be spelled in Carve.', $messages);
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
