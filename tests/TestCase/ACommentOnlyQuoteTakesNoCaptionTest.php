<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §4d, CARVE-P9-080: comment, definition and attribute lines hold no
 * content a caption could be about (carve#4220, option A).
 */
class ACommentOnlyQuoteTakesNoCaptionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hostlessQuotes(): array
    {
        return [
            'line comment' => ["> %%\n^ cap\n"],
            'fenced comment' => ["> %%%\n> x\n> %%%\n^ cap\n"],
            'attribute line and comment' => ["> {.c}\n> %%\n^ cap\n"],
            'two comments' => ["> %%\n> %% c\n^ cap\n"],
            'definition and comment' => ["> [l]: /u\n> %%\n^ cap\n"],
            'in a list item' => ["- a\n  > %%\n  ^ cap\n"],
        ];
    }

    #[DataProvider('hostlessQuotes')]
    public function testTheCaretLineStaysAParagraph(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringNotContainsString('<figure>', $html);
        $this->assertStringContainsString('^ cap', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hosts(): array
    {
        return [
            'paragraph and trailing comment' => ["> a %% c\n^ cap\n"],
            'comment before a paragraph' => ["> %%\n> a\n^ cap\n"],
            'comment after a paragraph' => ["> a\n> %%\n^ cap\n"],
        ];
    }

    #[DataProvider('hosts')]
    public function testAQuoteWithContentKeepsItsCaption(string $source): void
    {
        $this->assertStringContainsString(
            '<figcaption>cap</figcaption>',
            (new CarveConverter())->convert($source),
        );
    }
}
