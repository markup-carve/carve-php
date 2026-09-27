<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormattingAroundALinkKeepsOnlySeparatingSpaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'block edges' => ["<p><strong>\n <a href=\"/x\">mk</a>\n </strong></p>", "*[mk](/x)*\n"],
            'spaces outside too' => ["<p>a <strong>\n <a href=\"/x\">mk</a>\n </strong> b</p>", "a *[mk](/x)* b\n"],
            'only inner separators' => ['<p>a<strong> <a href="/x">mk</a> </strong>b</p>', "a{* [mk](/x) *}b\n"],
            'leading redundancy' => ['<p>a <strong> <a href="/x">mk</a> </strong>b</p>', "a {*[mk](/x) *}b\n"],
            'trailing redundancy' => ['<p>a<strong> <a href="/x">mk</a> </strong> b</p>', "a{* [mk](/x)*} b\n"],
            'nested formatting' => ['<p>a <strong> <em> <a href="/x">mk</a> </em> </strong> b</p>', "a */[mk](/x)/* b\n"],
            'outer paragraph padding' => ['<p> <strong> <a href="/x">mk</a> </strong> </p>', "*[mk](/x)*\n"],
            'whitespace-only formatting' => ['<p>a<strong> </strong>b</p>', "a{* *}b\n"],
            'code content' => ['<p>a <strong> <code> x </code> </strong> b</p>', "a *`  x  `* b\n"],
            'formatting without a link' => ['<p>a <strong> x </strong> b</p>', "a *x* b\n"],
            'superscript' => ['<p>a <sup> <a href="/x">mk</a> </sup> b</p>', "a {^[mk](/x)^} b\n"],
            'hard break' => ['<p>a<br><strong> <a href="/x">mk</a> </strong>b</p>', "a\\\n{*[mk](/x) *}b\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testOnlySeparatingSpaceStaysInside(string $html, string $expected): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($expected, $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
    }
}
