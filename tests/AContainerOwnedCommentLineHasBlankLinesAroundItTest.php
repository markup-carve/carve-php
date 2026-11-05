<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AContainerOwnedCommentLineHasBlankLinesAroundItTest extends TestCase
{
    /**
     * markup-carve/carve#5192 ruled option 2: a `%%` line a container owns gets a blank line before and after it.
     *
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'ruling: list item (corpus 87-6)' => [
                "- a\n\n  %% just a note\n- b\n",
                "- a\n\n  %% just a note\n\n- b\n",
            ],
            'ruling: quote' => [
                "> a\n> %% b\n",
                "> a\n>\n> %% b\n",
            ],
            'corpus 620-14' => [
                "- - a\n\n  %% just a note\n- b\n",
                "- - a\n\n  %% just a note\n\n- b\n",
            ],
            'corpus 504-18' => [
                ":: a\n: b\n  :: c\n  %% note\n  more\n",
                ":: a\n: b\n\n  :: c\n\n  %% note\n\n  more\n",
            ],
            'corpus 570: first child of its div' => [
                "> - ::: box\n>   - ::: inner\n>     %% c\n>    text\ntail\n",
                "> - ::: box\n>   - ::: inner\n>     %% c\n>     :::\n>\n>   text\n>   tail\n>   :::\n",
            ],
            'after a fence in a description' => [
                ":: t\n: ```\n  code\n  ```\n  %% note\n",
                ":: t\n: ```\n  code\n  ```\n\n  %% note\n",
            ],
            'after a fence in a quote' => [
                "> ```\n> code\n> ```\n> %% note\n",
                "> ```\n> code\n> ```\n>\n> %% note\n",
            ],
            'after a list in a quoted description' => [
                "- > :: t\n  > : - x\n  >   %% note\n- b\n",
                "- > :: t\n  > : - x\n  >\n  >   %% note\n- b\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testFmtWritesTheRuledLayout(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }

    public function testATightItemKeepsItsCommentAgainstTheParagraph(): void
    {
        // A blank would loosen the list and change the HTML (PART 11 §1).
        $source = "- a\n  %% x\n- b\n";

        self::assertSame($source, CarveConverter::toCarve($source));
    }

    public function testNoBlankAfterAnItemsLastCommentsWhereTheRunWouldEndTheList(): void
    {
        $source = "1. :: t\n   : # h\n\n     %% note\n\n     %% c2\n2. b\n";
        $formatted = CarveConverter::toCarve($source);

        // carve#5206 rules consecutive container-owned lines as one block.
        self::assertSame("1. :: t\n   : # h\n\n     %% note\n     %% c2\n\n2. b\n", $formatted);
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
