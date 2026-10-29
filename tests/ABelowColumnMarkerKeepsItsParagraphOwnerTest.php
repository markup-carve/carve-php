<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ABelowColumnMarkerKeepsItsParagraphOwnerTest extends TestCase
{
    use RuleATodo;

    public function testBelowColumnPlusStaysLiteralInQuotedItem(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote>\n    <ul>\n      <li>a\n&gt; q\n+\npara</li>\n    </ul>\n  </blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > - a\n  > q\n  +\n  para\n"),
        );
    }

    public function testDescriptionWithoutMatchingTermCannotOwnPlus(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n    </dl>\n  </blockquote>\n  <p>: a\n+</p>\n  <blockquote><p>q</p></blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > :: t\n> : a\n> +\n> > q\n"),
        );
    }

    public function testMatchingTermStillOwnsDescriptionAttachment(): void
    {
        self::assertStringContainsString(
            "<dd>\n      <p>a</p>\n      <blockquote><p>q</p></blockquote>\n    </dd>",
            CarveConverter::create()->convert("> :: t\n> : a\n> +\n> > q\n"),
        );
    }
}
