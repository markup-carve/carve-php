<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Importing `<x>C</x>` for an unsupported element gives the document `C` gives
 * in the same position, plus one `element-unwrapped` row for `x`
 * (markup-carve/carve#2341). Two nested wrappers used to flatten the block
 * under them into a paragraph.
 */
class AnUnsupportedElementImportsAsItsChildrenTest extends TestCase
{
    /**
     * @param string $wrapped
     * @param string $bare
     * @param array<int, string> $paths
     */
    #[DataProvider('shapeProvider')]
    public function testTheWrapperIsReplacedByItsChildren(string $wrapped, string $bare, array $paths): void
    {
        $converter = new HtmlToCarve();
        $wrappedResult = $converter->convertWithReport($wrapped);
        $bareResult = $converter->convertWithReport($bare);

        $this->assertSame($bareResult->value, $wrappedResult->value);
        $this->assertSame(
            $this->tree($converter->convertToAstWithReport($bare)->value),
            $this->tree($converter->convertToAstWithReport($wrapped)->value),
        );
        $this->assertSame([], $this->rows($bareResult->diagnostics));
        $this->assertSame(
            array_map(static fn (string $path): array => ['element-unwrapped', $path], $paths),
            $this->rows($wrappedResult->diagnostics),
        );
    }

    /**
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function shapeProvider(): array
    {
        return [
            'blocks under a custom element' => [
                '<react-app><div><h1>Title</h1><p>Body</p><ul><li>one</li></ul></div></react-app>',
                '<div><h1>Title</h1><p>Body</p><ul><li>one</li></ul></div>',
                ['/react-app[1]'],
            ],
            'inline context' => [
                '<p>a <tool-tip>b <em>c</em></tool-tip> d</p>',
                '<p>a b <em>c</em> d</p>',
                ['/p[1]/tool-tip[2]'],
            ],
            'mixed text and blocks' => [
                '<x-a>loose text<p>para</p>more</x-a>',
                'loose text<p>para</p>more',
                ['/x-a[1]'],
            ],
            'a non-hyphenated unknown tag' => [
                '<foo><h2>H</h2><pre><code>x = 1</code></pre></foo>',
                '<h2>H</h2><pre><code>x = 1</code></pre>',
                ['/foo[1]'],
            ],
            'two nested unknown elements' => [
                '<p>before</p><x-a><x-b><blockquote><p>q</p></blockquote></x-b></x-a><p>after</p>',
                '<p>before</p><blockquote><p>q</p></blockquote><p>after</p>',
                ['/x-a[2]', '/x-a[2]/x-b[1]'],
            ],
            'inside a list item' => [
                '<ul><li><x-a><p>one</p><p>two</p></x-a></li></ul>',
                '<ul><li><p>one</p><p>two</p></li></ul>',
                ['/ul[1]/li[1]/x-a[1]'],
            ],
            'two wrappers around a heading, list, code and table' => [
                '<x-a><x-b><h2>H</h2><ul><li>one</li></ul><pre><code>x</code></pre><table><tr><td>a</td></tr></table></x-b></x-a>',
                '<h2>H</h2><ul><li>one</li></ul><pre><code>x</code></pre><table><tr><td>a</td></tr></table>',
                ['/x-a[1]', '/x-a[1]/x-b[1]'],
            ],
        ];
    }

    /**
     * A spelled element keeps its own spelling: only an unsupported one is
     * looked through for a block.
     */
    public function testALinkAroundAWrappedHeadingStaysALink(): void
    {
        $this->assertSame(
            "[Title](/target)\n",
            (new HtmlToCarve())->convert('<a href="/target"><x-box><h2>Title</h2></x-box></a>'),
        );
    }

    /**
     * The input length is the one field the wrapper is allowed to change.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function tree(array $document): array
    {
        unset($document['srcByteLength']);

        return $document;
    }

    /**
     * @param array<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     *
     * @return array<int, array{string, string|null}>
     */
    private function rows(array $diagnostics): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [$diagnostic->code, $diagnostic->path],
            $diagnostics,
        );
    }
}
