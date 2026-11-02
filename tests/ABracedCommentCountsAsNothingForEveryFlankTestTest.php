<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#4719 (superseding ruling): a braced comment counts as nothing for every
 * flank test, so the dash flag test and the bare emphasis guards read past it.
 */
final class ABracedCommentCountsAsNothingForEveryFlankTestTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function flanks(): array
    {
        return [
            'flag after a leading comment' => ['{%%}--x', '<p>--x</p>'],
            'flag before a comment' => ['x --{%%}y', '<p>x --y</p>'],
            'dash after a word and a comment' => ['a{%%}--x', '<p>a–x</p>'],
            'flag after a span and a spaced comment' => ['*a* {%%}--x', '<p><strong>a</strong> --x</p>'],
            'word guard before an opener' => ['a{%%}*b*', '<p>a*b*</p>'],
            'two comments before an opener' => ['a{% c %}{%%}*b*', '<p>a*b*</p>'],
            'word guard after a closer' => ['*b*{%%}c', '<p>*b*c</p>'],
            'space before a closer' => ['*b {%%}*', '<p>*b *</p>'],
            'comments around a span' => ['x {%%}*b*{%%} y', '<p>x <strong>b</strong> y</p>'],
            'open guard before a combined span' => ['_{%%}/*b*/', '<p>_<strong><em>b</em></strong></p>'],
            'space before a combined closer' => ['/*b {%%}*/', '<p><em>*b *</em></p>'],
            'word after a combined closer' => ['/*b*/{%%}c', '<p>/<strong>b</strong>/c</p>'],
            'quote direction' => ['"a-"{%%}', '<p>“a-”</p>'],
        ];
    }

    #[DataProvider('flanks')]
    public function testTheFlankReadsPastTheComment(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAnImportedCommentBesideASpanBracesTheSpan(): void
    {
        $carve = (new HtmlToCarve())->convert('<p><strong>b</strong><!-- n -->c and x<!-- n --><em>y</em></p>');

        self::assertSame("{*b*}{%  n  %}c and x{%  n  %}{/y/}\n", $carve);
        self::assertSame('<p><strong>b</strong>c and x<em>y</em></p>', CarveConverter::create()->convert($carve));
    }

    public function testAnEmptyDjotAttributeBlockBracesTheSpanBesideIt(): void
    {
        $carve = (new DjotToCarve())->convert("_a_{}b\n");

        self::assertSame("{/a/}{%%}b\n", $carve);
        self::assertSame('<p><em>a</em>b</p>', CarveConverter::create()->convert($carve));
    }
}
