<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare marker is an empty item in CommonMark and text in Carve
 * (`CARVE-P2-009`), so the importer writes the first-block form (#2075).
 */
class AnEmptyMarkdownListItemIsAFirstBlockItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a bullet item' => ["- a\n-\n- b", "- a\n- +\n- b"],
            'an ordered item' => ["1. a\n2.\n3. b", "1. a\n2. +\n3. b"],
            'the only item' => ['-', '- +'],
            'a nested item' => ["- a\n  - b\n  -\n- c", "- a\n  - b\n  - +\n- c"],
            'after a heading' => ["# h\n-", "# h\n\n- +"],
            'after a block quote' => ["> q\n-", "> q\n\n- +"],
            'a setext underline after text' => ["text\n-", '## text'],
            'a marker indented into the item above' => ["- a\n  -\n- b", "- ## a\n- b"],
        ];
    }

    #[DataProvider('shapes')]
    public function testAnEmptyItemIsWrittenInTheFirstBlockForm(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
