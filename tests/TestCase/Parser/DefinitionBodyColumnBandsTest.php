<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description holds an open paragraph across a below-column text opener.
 * Column zero still opens in the document.
 */
class DefinitionBodyColumnBandsTest extends TestCase
{
    use RuleATodo;

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function belowTheColumnProvider(): array
    {
        return [
            'column 1' => [
                ":: t\n:  body\n > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body\n&gt; q</dd>\n</dl>\n",
            ],
            'column 2' => [
                ":: t\n:  body\n  > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body\n&gt; q</dd>\n</dl>\n",
            ],
        ];
    }

    #[DataProvider('belowTheColumnProvider')]
    public function testBelowTheColumnFoldsIntoTheOpenBody(string $source, string $expected): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $expected), $this->html($source));
    }

    public function testColumnZeroIsTheOrdinaryCaseNotASpecialOne(): void
    {
        // The body ends at column 0 too; what differs is that
        // `lazy_continuation_line` is written for a FLUSH-LEFT line, so it picks
        // a non-opener up there and nowhere else.
        $this->assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<blockquote><p>q</p></blockquote>\n"),
            $this->html(":: t\n:  body\n> q\n"),
        );
        $this->assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>t</dt>\n  <dd>body\ntail</dd>\n</dl>\n"),
            $this->html(":: t\n:  body\ntail\n"),
        );
    }

    public function testAtTheColumnTheLineIsTheBodysOwnBlockContent(): void
    {
        $this->assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n"),
            $this->html(":: t\n:  body\n   > q\n"),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pastTheColumnProvider(): array
    {
        return [
            'column 4' => [
                ":: t\n:  body\n    > q\n",
            ],
            'column 5' => [
                ":: t\n:  body\n     > q\n",
            ],
        ];
    }

    #[DataProvider('pastTheColumnProvider')]
    public function testPastTheColumnTheLineUsesItsAuthoredBase(string $source): void
    {
        $this->assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n"),
            $this->html($source),
        );
    }

    public function testAPlainLineBelowTheColumnIsLazyBodyText(): void
    {
        // Structural openers below the body column return to the surviving
        // container. Plain text remains the definition paragraph's lazy
        // continuation, as it does at column zero.
        $this->assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>t</dt>\n  <dd>body\ntail</dd>\n</dl>\n"),
            $this->html(":: t\n:  body\n tail\n"),
        );
    }
}
