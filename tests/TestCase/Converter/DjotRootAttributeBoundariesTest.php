<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
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

    private static function normalize(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/', $html, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $inCode = false;
        foreach ($parts as $n => $part) {
            if (str_starts_with($part, '<code')) {
                $inCode = true;
            } elseif ($part === '</code>') {
                $inCode = false;
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

        return preg_replace('/\n[ \t]+(<(?:ul|ol|blockquote|table)(?:\s|>))/', "\n$1", $html) ?? $html;
    }
}
