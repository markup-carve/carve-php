<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ASameKindSpanInABracedOneIsUnspellableTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function unwrapped(): array
    {
        return [
            "strong around a break" => ["<p>a<strong><b>x<br></b></strong>b</p>", "a{*{*x\\\n*}*}b\n", []],
            "emphasis around a break" => ["<p>a<em><i>x<br></i></em>b</p>", "a{/{/x\\\n/}/}b\n", []],
            "strong with a sibling" => ["<p>a<strong><b>x<br></b>y</strong>b</p>", "a{*{*x\\\n*}y*}b\n", []],
            "superscript" => ["<p><sup><sup>x</sup></sup></p>", "{^{^x^}^}\n", []],
            "subscript" => ["<p><sub><sub>x</sub></sub></p>", "{,{,x,},}\n", []],
            "insert" => ["<p><ins><ins>x</ins></ins></p>", "{+x+}\n", ["/p[1]/ins[1]/ins[1]"]],
            "delete" => ["<p><del><del>x</del></del></p>", "{-x-}\n", ["/p[1]/del[1]/del[1]"]],
            "any depth, and the neighbors respelled" => ["<p><sup><sup><em><sup>b</sup></em></sup><sup>b</sup></sup></p>", "{^{^/{^b^}/^}{^b^}^}\n", []],
            "an unwrapped sibling is read as its text" => ["<p><sup><em>a</em><sup>b</sup></sup></p>", "{^/a/{^b^}^}\n", []],
            "a bare inner level" => ["<p><strong><b>x</b></strong></p>", "{*{*x*}*}\n", []],
            "a bare outer level" => ["<p>c <strong><b>x<br></b></strong> d</p>", "c {*{*x\\\n*}*} d\n", []],
            "two inner spans" => ["<p><sup>a<sup>x</sup>b<sup>y</sup></sup></p>", "{^a{^x^}b{^y^}^}\n", []],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<string> $paths
     */
    #[DataProvider('unwrapped')]
    public function testTheImporterPreservesNativeAndUnwrapsEditorialNesting(string $html, string $carve, array $paths): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<string> $paths
     */
    #[DataProvider('unwrapped')]
    public function testTheImporterReportsOnlyEditorialNestingLoss(string $html, string $carve, array $paths): void
    {
        $rows = array_filter(
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'structure-unspellable',
        );

        $this->assertSame(
            array_map(static fn (string $path): array => [$path, 'warning'], $paths),
            array_values(array_map(static fn ($diagnostic): array => [$diagnostic->path, $diagnostic->severity], $rows)),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellable(): array
    {
        return [
            'a different kind between' => ['<p><strong><em><strong>x</strong></em></strong></p>', "{*/{*x*}/*}\n"],
            'a different kind between, both braced' => ['<p>a<strong><em>x<br></em></strong>b</p>', "a{*{/x\\\n/}*}b\n"],
        ];
    }

    #[DataProvider('spellable')]
    public function testASpellableNestingIsKept(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame([$carve, []], [$result->value, $result->diagnostics]);
    }

    public function testTheAstExitKeepsTheInnerLevel(): void
    {
        $this->assertSame(
            ['type' => 'strong', 'children' => [['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break']]]]],
            $this->at((new HtmlToCarve())->convertToAst('<p>a<strong><b>x<br></b></strong>b</p>'), 'children', 0, 'children', 1),
        );
    }

    public function testTheAstExitKeepsEveryLevelAtAnyDepth(): void
    {
        $html = '<p><sup><sup><em><sup>b</sup></em></sup><sup>b</sup></sup></p>';
        $superscript = static fn (array $children): array => ['type' => 'superscript', 'children' => $children];

        $this->assertSame(
            [
                $superscript([
                    $superscript([['type' => 'emphasis', 'children' => [$superscript([['type' => 'text', 'value' => 'b']])]]]),
                    $superscript([['type' => 'text', 'value' => 'b']]),
                ]),
            ],
            $this->at((new HtmlToCarve())->convertToAst($html), 'children', 0, 'children'),
        );
    }

    public function testTheAstExitKeepsTheInnerAttributes(): void
    {
        $this->assertSame(
            [['type' => 'superscript', 'attrs' => ['id' => 'i', 'classes' => ['inner'], 'order' => ['#id', '.class']], 'children' => [['type' => 'text', 'value' => 'x']]]],
            $this->at((new HtmlToCarve())->convertToAst('<p><sup><sup class="inner" id="i">x</sup></sup></p>'), 'children', 0, 'children', 0, 'children'),
        );
    }

    public function testTheAstExitIsNotStoppedByTheStandInNameAsText(): void
    {
        $html = '<p>data-carve-tree-kind <sup><sup>x</sup></sup></p>';

        $this->assertSame('superscript', $this->at((new HtmlToCarve())->convertToAst($html), 'children', 0, 'children', 1, 'children', 0, 'type'));
    }

    public function testTheAstExitLeavesTheStandInAttributeInTheInputAlone(): void
    {
        $html = '<p><span data-carve-tree-kind="strong">x</span> <sup><sup>y</sup></sup></p>';

        $this->assertSame('span', $this->at((new HtmlToCarve())->convertToAst($html), 'children', 0, 'children', 0, 'type'));
    }

    /**
     * @param array<mixed> $tree
     * @param string|int ...$path
     *
     * @return mixed
     */
    protected function at(array $tree, string|int ...$path): mixed
    {
        $value = $tree;
        foreach ($path as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    public function testAReusedImporterForgetsThePreviousDocument(): void
    {
        $importer = new HtmlToCarve();
        $importer->convert('<p>q<strong><strong>x</strong>y</strong>q</p>');

        $this->assertSame("q{*/x/*}q\n", $importer->convert('<p>q<strong><em>x</em></strong>q</p>'));
    }

    public function testTheWriterPreservesTheTree(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        [
                            'type' => 'strong',
                            'children' => [
                                ['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break']]],
                            ],
                        ],
                        ['type' => 'text', 'value' => 'b'],
                    ],
                ],
            ],
        ]);

        $this->assertSame("a{*{*x\\\n*}*}b\n", (new CarveRenderer())->render($document));
    }

    public function testTheWriterPreservesAtAnyDepth(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        [
                            'type' => 'strong',
                            'children' => [
                                ['type' => 'text', 'value' => 'y'],
                                ['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break']]],
                            ],
                        ],
                        ['type' => 'text', 'value' => 'b'],
                    ],
                ],
            ],
        ]);

        $this->assertSame("a{*y{*x\\\n*}*}b\n", (new CarveRenderer())->render($document));
    }

    public function testTheWriterPreservesABracedOnlyKind(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'superscript',
                            'children' => [
                                ['type' => 'superscript', 'children' => [['type' => 'text', 'value' => 'x']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame("{^{^x^}^}\n", (new CarveRenderer())->render($document));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellableTrees(): array
    {
        return [
            'a bare inner level' => ['{**x**}', '{**x**}'],
            'a different kind between' => ['*{/*x*/}*', '{*/{*x*}/*}'],
            'braced siblings' => ['{^a^}{^b^}', '{^a^}{^b^}'],
            'a braced different kind inside' => ['a{*c{/x/}d*}b', 'a{*c{/x/}d*}b'],
        ];
    }

    #[DataProvider('spellableTrees')]
    public function testTheWriterKeepsASpellableNesting(string $source, string $canonical): void
    {
        $document = CarveConverter::create()->parse($source);

        $this->assertSame($canonical . "\n", (new CarveRenderer())->render($document));
    }
}
