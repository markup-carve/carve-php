<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute line already parts two sibling lists, so a tight item writes no N1a boundary before it (carve#6005).
 */
class AnAttributeLineSeparatesSiblingListsInAnItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'quoted item with an empty plus item' => [
                "> - x\n>   - +\n>   {#i}\n>   - q\n",
                "> - x\n>   - {empty}\n>   {#i}\n>   - q\n",
            ],
            'nested item' => [
                "- x\n  - a\n  {#i}\n  - q\n",
                "- x\n  - a\n  {#i}\n  - q\n",
            ],
            'ordered sublists' => [
                "- x\n  1. a\n  {.c}\n  1. q\n",
                "- x\n  1. a\n  {.c}\n  1. q\n",
            ],
            'two levels deep' => [
                "- x\n  - y\n    * a\n    {k=v}\n    * q\n",
                "- x\n  - y\n    * a\n    {k=v}\n    * q\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testNoBoundaryBeforeAnAttributeLedList(string $source, string $expected): void
    {
        $converter = CarveConverter::create();
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($expected, $formatted);
        $this->assertSame($converter->convert($source), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
