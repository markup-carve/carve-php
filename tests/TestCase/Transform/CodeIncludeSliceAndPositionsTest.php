<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodeIncludeSliceAndPositionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, array<string, int>}>
     */
    public static function codeCases(): iterable
    {
        $region = "// #region a\nx\n\n// #endregion a\n// #region b\n\n// #endregion b\n// #region c\n// #endregion c\n";

        yield 'whole blank tail' => ['t.txt', "x\n\n\n", '@code', "<pre><code>x\n\n\n</code></pre>\n", self::pos(1, 1, 0, 3, 1, 3)];
        yield 'selected blank tail' => ['t.txt', "x\n\n\n", '@lines:1-3 @code', "<pre><code>x\n\n\n</code></pre>\n", self::pos(1, 1, 0, 3, 1, 3)];
        yield 'one blank line' => ['t.txt', "x\n\n\n", '@lines:2-2 @code', "<pre><code>\n</code></pre>\n", self::pos(2, 1, 2, 2, 1, 2)];
        yield 'language' => ['s.js', "a\nbb\n", '@code:js', "<pre><code class=\"language-js\">a\nbb\n</code></pre>\n", self::pos(1, 1, 0, 2, 3, 4)];
        yield 'region a' => ['r.js', $region, '@region:a @code', "<pre><code>x\n\n</code></pre>\n", self::pos(2, 1, 13, 3, 1, 15)];
        yield 'region b' => ['r.js', $region, '@region:b @code', "<pre><code>\n</code></pre>\n", self::pos(6, 1, 45, 6, 1, 45)];
        yield 'region c' => ['r.js', $region, '@region:c @code', "<pre><code></code></pre>\n", self::pos(9, 1, 75, 9, 1, 75)];
        yield 'crlf' => ['s.js', "a\r\nbb\r\n", '@code', "<pre><code>a\nbb\n</code></pre>\n", self::pos(1, 1, 0, 2, 3, 5)];
        yield 'empty file' => ['t.txt', '', '@code', "<pre><code></code></pre>\n", self::pos(1, 1, 0, 1, 1, 0)];
        yield 'bom only' => ['t.txt', "\u{FEFF}", '@code', "<pre><code></code></pre>\n", self::pos(1, 2, 1, 1, 2, 1)];
        yield 'range past the end' => ['s.js', "a\r\nbb\r\ncc\r\n", '@lines:2-9 @code', "<pre><code>bb\ncc\n</code></pre>\n", self::pos(2, 1, 3, 3, 3, 9)];
        yield 'bom content' => ['t.txt', "\u{FEFF}a\n", '@code', "<pre><code>a\n</code></pre>\n", self::pos(1, 2, 1, 1, 3, 2)];
    }

    /**
     * @return array<string, int>
     */
    private static function pos(int $startLine, int $startColumn, int $startOffset, int $endLine, int $endColumn, int $endOffset): array
    {
        return compact('startLine', 'endLine', 'startColumn', 'endColumn', 'startOffset', 'endOffset');
    }

    /**
      * @param string $file
     * @param string $content
     * @param string $options
     * @param string $html
@param array<string, int> $position
     */
    #[DataProvider('codeCases')]
    public function testCodePayloadAndPosition(string $file, string $content, string $options, string $html, array $position): void
    {
        [$actualHtml, $ast] = $this->expand("{{ {$file} {$options} }}\n", [$file => $content]);
        $this->assertSame($html, $actualHtml);
        $this->assertSame($position + ['file' => $file], $this->position($ast, 0));
    }

    public function testAttributedCodeIncludePosition(): void
    {
        [$html, $ast] = $this->expand("{table}\n{{ data.csv @code:csv }}\n", ['data.csv' => "a,b\n1,2\n"]);
        $this->assertSame("<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>1</td><td>2</td></tr>\n  </tbody>\n</table>\n", $html);
        $this->assertSame(self::pos(1, 1, 0, 2, 4, 7) + ['file' => 'data.csv'], $this->position($ast, 0));
    }

    public function testCarveSliceKeepsOriginalCrLfOffsets(): void
    {
        foreach (['@lines:1-3' => "one\r\n\r\ntwo\r\n", '@region:pick' => "%% #region pick\r\none\r\n\r\ntwo\r\n%% #endregion pick\r\n"] as $option => $content) {
            [$html, $ast] = $this->expand("{{ c.crv {$option} }}\n", ['c.crv' => $content]);
            $this->assertSame("<p>one</p>\n<p>two</p>\n", $html);
            $position = $this->position($ast, 1);
            $offset = $option === '@lines:1-3' ? 7 : 24;
            $this->assertSame($offset, $position['startOffset']);
            $this->assertSame($offset + 3, $position['endOffset']);
        }
    }

    /**
     * @param array<string, mixed> $ast
     * @param int $index
     *
     * @return array<string, mixed>
     */
    private function position(array $ast, int $index): array
    {
        $children = $ast['children'];
        $this->assertIsArray($children);
        $node = $children[$index];
        $this->assertIsArray($node);
        $position = $node['pos'];
        $this->assertIsArray($position);

        return $position;
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     *
     * @return array{string, array<string, mixed>}
     */
    private function expand(string $source, array $files): array
    {
        $converter = CarveConverter::create();
        $converter->getParser()->enablePositionTracking();
        $resolver = new class ($files) implements IncludeResolverInterface {
            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ?ResolvedInclude
            {
                return isset($this->files[$path]) ? new ResolvedInclude($this->files[$path], $path) : null;
            }
        };
        $document = $converter->transform($converter->parse($source), new IncludeExpander($resolver, 'entry.crv'));

        return [$converter->render($document), (new AstCodec())->encode($document)];
    }
}
