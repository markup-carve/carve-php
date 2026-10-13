<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotRootAttributeBoundariesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function boundaries(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-root-attribute-boundaries.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $n => $row) {
            yield 'boundary-' . $n => [$row['source'], $row['html'], $row['nativeAst'] ?? null];
        }
    }

    /**
     * @param string $source
     * @param string $expected
     * @param array<string, mixed>|null $nativeAst
     */
    #[DataProvider('boundaries')]
    public function testAttributeOwnership(string $source, string $expected, ?array $nativeAst): void
    {
        $converted = (new DjotToCarve())->convert($source);
        $renderer = new CarveConverter();
        $html = $renderer->convert($converted);
        if ($nativeAst !== null) {
            $list = $renderer->parse($converted)->getChildren()[0];
            self::assertInstanceOf(ListBlock::class, $list);
            $items = $list->getChildren();
            $original = $nativeAst['children'][0]['children'];
            self::assertCount(count($original), $items);
            if (($original[0]['children'][0]['tag'] ?? '') === 'block_quote') {
                self::assertCount(1, $items);
                $quote = $items[0]->getChildren()[0];
                self::assertInstanceOf(BlockQuote::class, $quote);
                $paragraphs = $quote->getChildren();
                $nativeParagraphs = $original[0]['children'][0]['children'];
                self::assertCount(count($nativeParagraphs), $paragraphs);
                foreach ($paragraphs as $n => $paragraph) {
                    self::assertInstanceOf(Paragraph::class, $paragraph);
                    self::assertSame($nativeParagraphs[$n]['attributes']['class'] ?? '', implode(' ', $paragraph->getClassList()));
                }
                self::assertStringContainsString('<p class="x">para</p>', $html);

                return;
            }
            foreach ($items as $n => $item) {
                self::assertInstanceOf(ListItem::class, $item);
                self::assertSame($original[$n]['checkbox'] === 'checked', $item->getChecked(), $source);
                self::assertSame($original[$n]['attributes']['class'] ?? '', implode(' ', $item->getClassList()), $source);
            }
            if (str_contains($source, '_c_')) {
                self::assertStringContainsString("<pre><code>_c_\n</code></pre>", $html);
            }

            return;
        }
        self::assertSame(self::normalize($expected), self::normalize($html), $source . "\n" . $converted);
    }

    public function testPendingItemAttributesReturnWhenAFootnoteCloses(): void
    {
        $converted = (new DjotToCarve())->convert("[^n]: - x\n\n        {.a}\n\np[^n]");
        $html = preg_replace('/>\s+</', '><', (new CarveConverter())->convert($converted));
        self::assertStringNotContainsString("\0", $converted);
        self::assertStringContainsString('<p class="a">p<a', $html);
        self::assertStringContainsString('<ul><li><p>x</p></li></ul>', $html);
    }

    public function testAReferencedFootnoteHeadKeepsOneTaskList(): void
    {
        $converted = (new DjotToCarve())->convert("[^n]: - [ ] a\n      - [ ] b\n\nx[^n]");
        $html = (new CarveConverter())->convert($converted);
        self::assertStringNotContainsString("\0", $converted);
        self::assertSame(1, preg_match_all('/<ul(?: |>)/', $html));
        self::assertSame(2, substr_count($html, 'type="checkbox"'));
    }

    public function testNestedFootnoteItemAttributesReturnBeforeTheParentFence(): void
    {
        $converted = (new DjotToCarve())->convert("[^a]: - x\n\n      [^b]: - y\n\n              {.c}\n\n      ```\n      z\n      ```\n\np[^a] q[^b]");
        $html = (new CarveConverter())->convert($converted);
        self::assertStringNotContainsString("\0", $converted);
        self::assertStringContainsString("<pre class=\"c\"><code>z\n</code></pre>", $html);
        self::assertStringNotContainsString('<p class="c">', $html);
    }

    public function testAFootnoteParagraphKeepsOneLiteralQuoteMarker(): void
    {
        $converted = (new DjotToCarve())->convert("[^n]: > a\n      > >\n\nx[^n]");
        $html = preg_replace('/>\s+</', '><', (new CarveConverter())->convert($converted));
        self::assertStringContainsString("<blockquote><p>a\n&gt;</p></blockquote>", $html);
    }

    private static function normalize(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/', $html, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $codeText = [];
        $codePrefix = 'DJOTCODETEXT';
        while (str_contains($html, $codePrefix)) {
            $codePrefix .= 'X';
        }
        $inCode = false;
        $inBacklink = false;
        foreach ($parts as $n => $part) {
            if (str_starts_with($part, '<a ') && str_contains($part, 'role="doc-backlink"')) {
                $inBacklink = true;
            } elseif ($part === '</a>') {
                $inBacklink = false;
            } elseif ($inBacklink && !str_starts_with($part, '<')) {
                $parts[$n] = $part = str_replace("\u{FE0E}", '', $part);
            }
            if (str_starts_with($part, '<code')) {
                $inCode = true;
            } elseif ($part === '</code>') {
                $inCode = false;
            } elseif ($inCode && !str_starts_with($part, '<')) {
                $token = $codePrefix . count($codeText) . 'Z';
                $codeText[$token] = htmlspecialchars(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $parts[$n] = $token;
            } elseif (!$inCode && !str_starts_with($part, '<')) {
                $parts[$n] = preg_replace('/[ \t\r\n]+/', ' ', $part) ?? $part;
            }
        }
        $html = implode('', $parts);
        $html = preg_replace_callback('/<([a-zA-Z][a-zA-Z0-9-]*)([^<>]*)>/', static function (array $tag): string {
            preg_match_all('/([a-zA-Z][a-zA-Z0-9-]*)(?:="([^"]*)")?/', $tag[2], $attributes, PREG_SET_ORDER);
            usort($attributes, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
            $text = '';
            foreach ($attributes as $attribute) {
                $text .= ' ' . $attribute[1] . (isset($attribute[2]) ? '="' . $attribute[2] . '"' : '');
            }

            return '<' . $tag[1] . $text . '>';
        }, $html) ?? $html;

        $html = preg_replace('/ (?:scope="col"|aria-label="[^"]*"|data-delim="[^"]*"|data-task-state="[^"]*")/', '', $html) ?? $html;
        $html = preg_replace_callback('/<input[^>]*>\s*/', static fn (array $match): string => str_contains($match[0], ' checked') ? '<input checked>' : '<input>', $html) ?? $html;
        $html = preg_replace('/<\/?(?:thead|tbody)>/', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', trim($html)) ?? $html;
        $html = preg_replace('/(<(?:li|p)(?: [^>]*)?>)[ \t\r\n]+/', '$1', $html) ?? $html;
        $html = preg_replace('/[ \t\r\n]+(<\/(?:li|p)>)/', '$1', $html) ?? $html;

        return strtr(preg_replace('/\n[ \t]+(<(?:ul|ol|blockquote|table)(?:\s|>))/', "\n$1", $html) ?? $html, $codeText);
    }
}
