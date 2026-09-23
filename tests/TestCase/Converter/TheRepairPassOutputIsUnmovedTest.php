<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `repairUnwrittenConstructs()` writes, shape by shape.
 *
 * The repair pass decides where to put an escape by reading the POSITIONS the
 * repair parse reports, so any change to how a position is computed can move
 * this output - silently, and only on the shapes where an escape lands
 * somewhere an eye would not look (carve-php#2238).
 *
 * Every string below was measured on the commit before that work, so the table
 * says what the pass did then and pins that it still does. The shapes are the
 * seams the pass exists for: pairs that touch, pairs inside pairs of the same
 * and of another kind, tags that write nothing, an escape the post itself
 * carried on either side, and text whose bytes are not ASCII - plus ordinary
 * punctuated prose, which is what a forum post actually looks like and which
 * every shape-level fast path in this converter has to agree with.
 */
class TheRepairPassOutputIsUnmovedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapeProvider(): array
    {
        return [
            'adjacent pairs' => ['[b]a[/b][b]b[/b]', "*a*{*b*}\n"],
            'adjacent pairs of different kinds' => ['[b]a[/b][i]b[/i][u]c[/u][s]d[/s]', "*a*/b/{_c_}~d~\n"],
            'nested same kind' => ['[b]a[b]b[/b]c[/b]', "*abc*\n"],
            'nested different kinds' => ['[b]a[i]b[u]c[/u]d[/i]e[/b]', "*a{/b{_c_}d/}e*\n"],
            'empty tags' => ['[b][/b][i][/i]x[u][/u]', "x\n"],
            'an unclosed tag' => ['[b]a[i]b', "[b]a[i]b\n"],
            'a stray closer' => ['a[/b]b', "ab\n"],
            'escaped punctuation before' => ['\\*[b]x[/b]', "\\\\\\*{*x*}\n"],
            'escaped punctuation after' => ['[b]x[/b]\\*', "*x*\\\\*\n"],
            'escaped punctuation either side' => ['\\*[b]x[/b]\\*', "\\\\\\*{*x*}\\\\*\n"],
            'a delimiter either side' => ['*[b]x[/b]*', "\\*{*x*}*\n"],
            'a no-break space either side' => ["a\u{00A0}[b]x[/b]\u{00A0}b", "a\u{00A0}*x*\u{00A0}b\n"],
            'an em dash either side' => ["a\u{2014}[b]x[/b]\u{2014}b", "a\u{2014}*x*\u{2014}b\n"],
            'punctuated prose' => ['I tried it, and it worked. See [b]this[/b]!', "I tried it, and it worked. See *this*!\n"],
            'an apostrophe and a hyphen' => ["it's a well-known [i]thing[/i].", "it's a well-known /thing/.\n"],
            'a hashtag and an equals run beside a tag' => ['#x [b]y[/b] =z=', "\\#x *y* \\=z=\n"],
            'a mention beside a tag' => ['@x [b]y[/b]', "\\@x *y*\n"],
            'a colon name before a tag' => [':name: [b]y[/b]', "\\:name: *y*\n"],
            'a brace either side' => ['{[b]x[/b]}', "{*x*\\}\n"],
            'a link beside a pair' => ['[url=http://e.com]t[/url] [b]x[/b].', "[t](http://e.com) *x*.\n"],
            'brackets the post wrote' => ['[x[b]y[/b](z)', "[x{*y*}(z)\n"],
            'several lines' => ["Hi, [b]x[/b].\nAnd [i]y[/i]?\n\nNew, [u]z[/u]!", "Hi, *x*.\nAnd /y/?\n\nNew, _z_!\n"],
            'a quote around formatting' => ['[quote=Ann]Well, [b]yes[/b].[/quote]', "> Well, *yes*.\n^ Ann\n"],
            'a list around formatting' => ['[list][*]one, [b]two[/b].[*]three![/list]', "- one, *two*.\n- three!\n"],
            'code beside formatting' => ['[code]a, b.[/code] then [b]x[/b].', "```\na, b.\n```\n\n then *x*.\n"],
        ];
    }

    #[DataProvider('shapeProvider')]
    public function testTheShapeConvertsAsItDid(string $bbcode, string $expected): void
    {
        $this->assertSame($expected, (new BbcodeToCarve())->convert($bbcode));
    }

    /**
     * The same seams FAR INTO ONE LINE, where a position is resolved with the
     * whole post behind it. That is the only place the fix can change anything,
     * and the output is too large to spell, so it is pinned by digest - taken,
     * like the table above, from the commit before the fix.
     */
    public function testALongOneLinePostConvertsAsItDid(): void
    {
        $post = '';
        foreach (self::shapeProvider() as [$bbcode, $ignored]) {
            if (str_contains($bbcode, "\n")) {
                continue;
            }
            $post .= $bbcode . ' ';
        }
        $post = str_repeat($post, 200);

        $converted = (new BbcodeToCarve())->convert($post);

        $this->assertSame(67600, strlen($converted));
        $this->assertSame(
            '2ce9fefca42477ab91963f4be155105a5338265b94d2e246e5274b45eb8e2d53',
            hash('sha256', $converted),
        );
    }
}
