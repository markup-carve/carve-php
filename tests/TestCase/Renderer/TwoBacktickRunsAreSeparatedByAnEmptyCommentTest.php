<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two backtick runs that touch merge into one, so the writer and the HTML
 * importer separate them with an empty delimited comment, which renders
 * nothing and compares equal to nothing (PART 11 section 10k N3, ruled on
 * markup-carve/carve-js#1818).
 */
class TwoBacktickRunsAreSeparatedByAnEmptyCommentTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    protected const CODE_A = ['type' => 'code', 'value' => 'a'];

    /**
     * @var array<string, mixed>
     */
    protected const CODE_B = ['type' => 'code', 'value' => 'b'];

    /**
     * @return array<string, array{array<int, array<string, mixed>>, string}>
     */
    public static function written(): array
    {
        return [
            'two code spans' => [[self::CODE_A, self::CODE_B], "`a`{%  %}`b`\n"],
            'three code spans' => [
                [self::CODE_A, self::CODE_B, ['type' => 'code', 'value' => 'c']],
                "`a`{%  %}`b`{%  %}`c`\n",
            ],
            'a code span before a raw inline' => [
                [self::CODE_A, ['type' => 'raw_inline', 'format' => 'html', 'content' => '<b>']],
                "`a`{%  %}`<b>`{=html}\n",
            ],
            'a literal before a code span' => [
                [['type' => 'literal_inline', 'content' => 'a'], self::CODE_B],
                "!`a`{%  %}`b`\n",
            ],
            'math before a code span' => [
                [['type' => 'math', 'content' => 'a', 'display' => false], self::CODE_B],
                "\$`a`{%  %}`b`\n",
            ],
            'an attribute block on the second span' => [
                [self::CODE_A, ['type' => 'code', 'value' => 'b', 'attrs' => ['classes' => ['x']]]],
                "`a`{%  %}`b`{.x}\n",
            ],
            'text between the spans' => [
                [self::CODE_A, ['type' => 'text', 'value' => 'z'], self::CODE_B],
                "`a`z`b`\n",
            ],
            'a raw inline before a code span' => [
                [['type' => 'raw_inline', 'format' => 'html', 'content' => '<b>'], self::CODE_A],
                "`<b>`{=html}`a`\n",
            ],
            'an escaped backtick before a code span' => [
                [['type' => 'text', 'value' => 'x`'], self::CODE_A],
                "x\\``a`\n",
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @param string $carve
     */
    #[DataProvider('written')]
    public function testTheWriterSeparatesTheRuns(array $children, string $carve): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => $children]],
        ]);

        $written = (new CarveRenderer())->render($document);

        $this->assertSame($carve, $written);
        $this->assertSame(
            (new CarveConverter())->render($document),
            (new CarveConverter())->convert($written),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function imported(): array
    {
        return [
            'two code spans' => ['<p><code>a</code><code>b</code></p>', "`a`{%  %}`b`\n"],
            'text around them' => ['<p>t<code>a</code><code>b</code>z</p>', "t`a`{%  %}`b`z\n"],
            'text between them' => ['<p><code>a</code>z<code>b</code></p>', "`a`z`b`\n"],
            'a backtick in the text before' => ['<p>x `y` <code>a</code><code>b</code></p>', "x \\`y\\` `a`{%  %}`b`\n"],
        ];
    }

    #[DataProvider('imported')]
    public function testTheImporterSeparatesTheRuns(string $html, string $carve): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($carve, $imported);
        $this->assertSame(
            substr_count($html, '<code>'),
            substr_count((new CarveConverter())->convert($imported), '<code>'),
        );
    }
}
