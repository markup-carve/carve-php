<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DivHostedQuoteContinuationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function divKinds(): array
    {
        return [
            'note' => ['note', 'aside class="admonition note" aria-label="Note"'],
            'generic' => ['box', 'div class="box"'],
            'warning' => ['warning', 'aside class="admonition warning" aria-label="Warning"'],
        ];
    }

    #[DataProvider('divKinds')]
    public function testLastMarkerInHostedQuoteBecomesASeparateParagraph(string $kind, string $outer): void
    {
        $source = "::: {$kind}\n> q\n+\n+\n:::\n";
        $expected = "<{$outer}>\n  <blockquote>\n    <p>q</p>\n    <p>+</p>\n  </blockquote>\n</" . explode(' ', $outer)[0] . '>';

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testNestedDivCloserCannotAttachToTheQuote(): void
    {
        self::assertSame(
            "<div class=\"outer\">\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <blockquote>\n      <p>q</p>\n      <p>+</p>\n    </blockquote>\n  </aside>\n</div>",
            CarveConverter::create()->convert(":::: outer\n::: note\n> q\n+\n+\n:::\n::::\n"),
        );
    }

    public function testBareDivOpenerCanStillAttachOutsideADiv(): void
    {
        self::assertSame(
            "<blockquote>\n  <p>q</p>\n  <div>\n\n  </div>\n</blockquote>",
            CarveConverter::create()->convert("> q\n+\n:::\n"),
        );
    }

    public function testColonTextInsideCodeDoesNotOpenADivHost(): void
    {
        self::assertSame(
            "<pre><code>::: note\n</code></pre>\n<blockquote>\n  <p>q</p>\n  <div>\n\n  </div>\n</blockquote>",
            CarveConverter::create()->convert("```\n::: note\n```\n> q\n+\n:::\n"),
        );
    }
}
