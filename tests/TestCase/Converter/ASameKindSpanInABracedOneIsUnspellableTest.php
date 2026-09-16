<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A braced span inside a braced span of the same kind has no spelling, at any
 * depth, since PART 9 §9 E3 leaves the inner opener literal (PART 11 §1c,
 * markup-carve/carve#2066).
 */
class ASameKindSpanInABracedOneIsUnspellableTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function unwrapped(): array
    {
        return [
            'strong around a break' => ['<p>a<strong><b>x<br></b></strong>b</p>', "a{*x\\\n*}b\n", ['/p[1]/strong[2]/b[1]']],
            'emphasis around a break' => ['<p>a<em><i>x<br></i></em>b</p>', "a{/x\\\n/}b\n", ['/p[1]/em[2]/i[1]']],
            'strong with a sibling' => ['<p>a<strong><b>x<br></b>y</strong>b</p>', "a{*x\\\ny*}b\n", ['/p[1]/strong[2]/b[1]']],
            'superscript' => ['<p><sup><sup>x</sup></sup></p>', "{^x^}\n", ['/p[1]/sup[1]/sup[1]']],
            'subscript' => ['<p><sub><sub>x</sub></sub></p>', "{,x,}\n", ['/p[1]/sub[1]/sub[1]']],
            'insert' => ['<p><ins><ins>x</ins></ins></p>', "{+x+}\n", ['/p[1]/ins[1]/ins[1]']],
            'delete' => ['<p><del><del>x</del></del></p>', "{-x-}\n", ['/p[1]/del[1]/del[1]']],
            'any depth, and the neighbors respelled' => [
                '<p><sup><sup><em><sup>b</sup></em></sup><sup>b</sup></sup></p>',
                "{^{/b/}b^}\n",
                ['/p[1]/sup[1]/sup[1]/em[1]/sup[1]', '/p[1]/sup[1]/sup[1]', '/p[1]/sup[1]/sup[2]'],
            ],
            'an unwrapped sibling is read as its text' => ['<p><sup><em>a</em><sup>b</sup></sup></p>', "{^{/a/}b^}\n", ['/p[1]/sup[1]/sup[2]']],
            'two inner spans' => ['<p><sup>a<sup>x</sup>b<sup>y</sup></sup></p>', "{^axby^}\n", ['/p[1]/sup[1]/sup[2]', '/p[1]/sup[1]/sup[4]']],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<string> $paths
     */
    #[DataProvider('unwrapped')]
    public function testTheImporterUnwrapsTheInnerLevel(string $html, string $carve, array $paths): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<string> $paths
     */
    #[DataProvider('unwrapped')]
    public function testTheImporterReportsTheInnerLevel(string $html, string $carve, array $paths): void
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
            'a bare inner level' => ['<p><strong><b>x</b></strong></p>', "{**x**}\n"],
            'a different kind between' => ['<p><strong><em><strong>x</strong></em></strong></p>', "*{/*x*/}*\n"],
            'a bare outer level' => ['<p>c <strong><b>x<br></b></strong> d</p>', "c *{*x\\\n*}* d\n"],
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

        $this->assertSame("q{**x**}q\n", $importer->convert('<p>q<strong><strong>x</strong></strong>q</p>'));
    }

    public function testTheWriterRefusesTheTree(): void
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

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testTheWriterRefusesAtAnyDepth(): void
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
                                [
                                    'type' => 'emphasis',
                                    'children' => [
                                        ['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break']]],
                                    ],
                                ],
                            ],
                        ],
                        ['type' => 'text', 'value' => 'b'],
                    ],
                ],
            ],
        ]);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testTheWriterRefusesABracedOnlyKind(): void
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

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellableTrees(): array
    {
        return [
            'a bare inner level' => ['{**x**}'],
            'a different kind between' => ['*{/*x*/}*'],
            'braced siblings' => ['{^a^}{^b^}'],
            'a braced different kind inside' => ['a{*c{/x/}d*}b'],
        ];
    }

    #[DataProvider('spellableTrees')]
    public function testTheWriterKeepsASpellableNesting(string $source): void
    {
        $document = CarveConverter::create()->parse($source);

        $this->assertSame($source . "\n", (new CarveRenderer())->render($document));
    }
}
