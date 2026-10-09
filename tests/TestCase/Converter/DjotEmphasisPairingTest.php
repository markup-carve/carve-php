<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotEmphasisPairingTest extends TestCase
{
    public function testPairingFollowsDjotClosers(): void
    {
        foreach (
            [
                ['foo*bar*baz', '<p>foo<strong>bar</strong>baz</p>'],
                ['_(_foo_)_', '<p><em>(</em>foo<em>)</em></p>'],
                ['___', '<p>___</p>'],
                ['_}b_', '<p>_}b_</p>'],
                ['_[bar_](url)', '<p><em>[bar</em>](url)</p>'],
                ['<http://a_b_c>', '<p><a href="http://a_b_c">http://a_b_c</a></p>'],
                ['(some text){.attr}', '<p>(some <span class="attr">text)</span></p>'],
                ['~_x_~', '<p><sub><em>x</em></sub></p>'],
                ['^_x_^', '<p><sup><em>x</em></sup></p>'],
                ['_a_+ b', '<p><em>a</em>+ b</p>'],
                ['_a {.c}_', '<p><em>a </em></p>'],
                ['*a {.c}*', '<p><strong>a </strong></p>'],
                ["[r]: /u_v\n\n[x][r]", '<p><a href="/u_v">x</a></p>'],
                ['![alt_x](u.png)', '<img src="u.png" alt="alt_x">'],
                ['a' . "\n{.c}\n" . 'b', '<p>a' . "\n\n" . 'b</p>'],
                ['_emph_{.a}', '<p><em class="a">emph</em></p>'],
                ['{+ins+}{.a}', '<p><ins class="a">ins</ins></p>'],
            ] as [$source, $expected]
        ) {
            $carve = (new DjotToCarve())->convert($source);
            $this->assertSame($expected, trim((new CarveConverter())->convert($carve)), $source);
        }
    }

    public function testClosedBlocksLeaveAttributesPending(): void
    {
        foreach (["```\nx\n```", "::: box\nx\n:::", '***', '| a |', '[r]: /u'] as $block) {
            $carve = (new DjotToCarve())->convert("{$block}\n{.c}\npara");
            $this->assertStringContainsString('<p class="c">para</p>', (new CarveConverter())->convert($carve));
        }
    }

    public function testDeepSameKindSpansDoNotUseTheCallStack(): void
    {
        $source = str_repeat('{_', 10000) . 'x' . str_repeat('_}', 10000);
        $this->assertSame('{/x/}', (new DjotToCarve())->convert($source));
    }

    public function testRawBlocksKeepLiteralDelimiters(): void
    {
        $carve = (new DjotToCarve())->convert("``` =html\n<b>_x</b>\n```");
        $this->assertSame('<b>_x</b>', trim((new CarveConverter())->convert($carve)));
        foreach (['```', '~~~'] as $fence) {
            $carve = (new DjotToCarve())->convert(": {$fence}\n  a_b\n  {$fence}");
            $this->assertStringContainsString("<pre><code>a_b\n</code></pre>", (new CarveConverter())->convert($carve));
        }
    }

    public function testParagraphMarkerTextAndImageAlts(): void
    {
        $converter = new DjotToCarve();
        foreach (["_a\n- b_", "_a\n1. b_", "_a\n| b_", "para _a\n  - b_", ": ```\n  _x\n  ```\n\n  _y_"] as $source) {
            self::assertStringContainsString('<em>', (new CarveConverter())->convert($converter->convert($source)));
        }
        foreach (['![basic _image_](url)', "![basic _image_][a_b_]\n\n[a_b_]: url"] as $source) {
            self::assertStringContainsString('alt="basic image"', (new CarveConverter())->convert($converter->convert($source)));
        }
    }

    public function testImporterContextRegressions(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/djot-import-context.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as [$source, $fragment]) {
            self::assertStringContainsString($fragment, (new CarveConverter())->convert((new DjotToCarve())->convert($source)), $source);
        }
    }
}
