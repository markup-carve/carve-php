<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ASecondContinuationMarkerStartsItsOwnParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function hosts(): array
    {
        return [
            'quote' => ["> q\n+\n+\n", "<blockquote>\n  <p>q</p>\n  <p>+</p>\n</blockquote>"],
            'loose list' => ["- q\n+\n+\n\n- r\n", "<ul>\n  <li><p>q</p>\n    <p>+</p>\n  </li>\n  <li><p>r</p></li>\n</ul>"],
            'description' => [":: t\n: q\n+\n+\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>q</p>\n    <p>+</p>\n  </dd>\n</dl>"],
            'footnote' => ["[^a]: q\n+\n+\n\nref [^a]\n", "<p>ref <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>q</p>\n      <p>+<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
        ];
    }

    #[DataProvider('hosts')]
    public function testMarkerParagraphIsSeparateInEveryHost(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quoteFollowers(): array
    {
        return [
            'paragraph' => ['text', '<p>text</p>'],
            'list' => ['- s', "<ul>\n    <li>s</li>\n  </ul>"],
            'heading' => ['# h', '<h1 id="h">h</h1>'],
        ];
    }

    #[DataProvider('quoteFollowers')]
    public function testFirstMarkerRemainsQuoteTextWhenSecondAttaches(string $follower, string $expected): void
    {
        $source = "> q\n+\n+\n{$follower}\n";
        self::assertSame("<blockquote>\n  <p>q\n+</p>\n  {$expected}\n</blockquote>", CarveConverter::create()->convert($source));
    }
}
