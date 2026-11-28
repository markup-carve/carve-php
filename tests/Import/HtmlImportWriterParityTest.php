<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML imports whose written source and loss report carve-js and carve-rs already give.
 */
final class HtmlImportWriterParityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function includeShapedText(): iterable
    {
        // carve-php#6152: the bare line reads as an include, so its first brace takes the backslash.
        yield 'include line' => ['<p>{{ a.crv #pick }}</p>', "\\{{ a.crv \\#pick }}\n"];
        yield 'include line without a section' => ['<p>{{ a.crv }}</p>', "\\{{ a.crv }}\n"];
        yield 'include shape behind text' => ['<p>x {{ a.crv #pick }}</p>', "x {{ a.crv \\#pick }}\n"];
    }

    #[DataProvider('includeShapedText')]
    public function testIncludeShapedTextEscapesTheLineOpener(string $html, string $source): void
    {
        $result = Carve::importHtml($html);

        self::assertSame($source, $result->source);
        self::assertSame([], self::losses($result->diagnostics));
    }

    public function testDocumentLangLossKeepsTheCellEscapes(): void
    {
        // carve-php#6155
        $cells = '<table><tr><td>Table headers</td><td><code>---</code></td><td><code>---</code></td><td>underline</td>'
            . '<td>`</td><td>===`</td><td><code>|---+</code></td></tr></table>';
        $row = "| Table headers | `---` | `---` | underline | \\` | \\===\\` | `|---+` |\n";

        $withLang = Carve::importHtml('<!doctype html><html lang="en"><body>' . $cells . '</body></html>');
        $fragment = Carve::importHtml($cells);

        self::assertSame($row, $withLang->source);
        self::assertSame($fragment->source, $withLang->source);
        self::assertSame(['field-unspellable /lang'], self::losses($withLang->diagnostics));
    }

    /**
     * @return iterable<string, array{string, array<string>}>
     */
    public static function emptyItems(): iterable
    {
        // carve-php#6157: the empty item leaves its list, or a block beside it, reading back as another.
        yield 'lone empty item' => [
            '<ul><li></li></ul>', [
                'structure-unspellable /children/0',
                'structure-unspellable /children/0/children/0',
            ],
        ];

        yield 'two empty items' => [
            '<ol><li></li><li></li></ol>', [
                'structure-unspellable /children/0',
                'structure-unspellable /children/0/children/0',
                'structure-unspellable /children/0/children/1',
            ],
        ];

        yield 'empty item after an item' => [
            '<ul><li>a</li><li></li></ul>', [
                'structure-unspellable /children/0/children/0/children/0',
                'structure-unspellable /children/0/children/1',
            ],
        ];

        yield 'empty item in a nested list' => [
            '<ul><li>a<ul><li></li></ul></li></ul>', [
                'structure-unspellable /children/0/children/0/children/0',
                'structure-unspellable /children/0/children/0/children/1',
                'structure-unspellable /children/0/children/0/children/1/children/0',
            ],
        ];

        yield 'empty item in a quote' => [
            '<blockquote><ul><li></li></ul></blockquote>', [
                'structure-unspellable /children/0/children/0',
                'structure-unspellable /children/0/children/0/children/0',
            ],
        ];

        yield 'paragraphs around the list' => [
            '<p>p</p><ul><li></li><li></li></ul><p>q</p>', [
                'structure-unspellable /children/1',
                'structure-unspellable /children/1/children/0',
                'structure-unspellable /children/1/children/1',
            ],
        ];

        yield 'empty description keeps its list' => [
            '<dl><dt>t</dt><dd></dd></dl>', [
                'structure-unspellable /children/0/children/1',
            ],
        ];
    }

    /**
     * @param string $html
     * @param array<string> $losses
     */
    #[DataProvider('emptyItems')]
    public function testEmptyItemReportsTheBlocksThatDoNotReadBack(string $html, array $losses): void
    {
        self::assertSame($losses, self::losses(Carve::importHtml($html)->diagnostics));
    }

    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<string>
     */
    private static function losses(array $diagnostics): array
    {
        $losses = array_filter($diagnostics, static fn (Diagnostic $d): bool => str_ends_with($d->code, '-unspellable'));

        return array_values(array_map(static fn (Diagnostic $d): string => $d->code . ' ' . $d->path, $losses));
    }
}
