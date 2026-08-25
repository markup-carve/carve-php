<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body has three column bands: BELOW its content column the body
 * ENDS and the line is classified in the surviving context; AT the column the
 * line is the body's own block content; PAST the column a recognized opener
 * establishes an authored local base.
 *
 * This ladder is what makes the floor observable at all, since a reader whose
 * floor was lowered from column 3 to column 1 would otherwise pass every
 * existing document.
 */
class DefinitionBodyColumnBandsTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * One and two columns reach neither the body's column nor flush-left, so
     * nothing folds them in; the body ends and the document reads the line by
     * its own rules, where an indented `>` is a paragraph under the strict
     * column-0 opener rule. Folding it in as lazy text would give a sub-column
     * indent the PAST band's meaning, which is the third meaning the clause
     * refuses.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function belowTheColumnProvider(): array
    {
        return [
            'column 1' => [
                ":: t\n:  body\n > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>&gt; q</p>\n",
            ],
            'column 2' => [
                ":: t\n:  body\n  > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>&gt; q</p>\n",
            ],
        ];
    }

    #[DataProvider('belowTheColumnProvider')]
    public function testBelowTheColumnTheBodyEndsAndTheLineIsReclassified(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testColumnZeroIsTheOrdinaryCaseNotASpecialOne(): void
    {
        // The body ends at column 0 too; what differs is that
        // `lazy_continuation_line` is written for a FLUSH-LEFT line, so it picks
        // a non-opener up there and nowhere else.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<blockquote><p>q</p></blockquote>\n",
            $this->html(":: t\n:  body\n> q\n"),
        );
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>body\ntail</dd>\n</dl>\n",
            $this->html(":: t\n:  body\ntail\n"),
        );
    }

    public function testAtTheColumnTheLineIsTheBodysOwnBlockContent(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
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
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            $this->html($source),
        );
    }

    public function testAPlainLineBelowTheColumnEndsTheBodyToo(): void
    {
        // The band, not the line's shape, is what decides - so a line that
        // opens nothing ends the body just the same when it is below the column.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>tail</p>\n",
            $this->html(":: t\n:  body\n tail\n"),
        );
    }
}
