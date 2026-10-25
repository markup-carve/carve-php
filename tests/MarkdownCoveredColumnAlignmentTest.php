<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownCoveredColumnAlignmentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function tables(): array
    {
        return [
            'corpus 659-4' => [
                "|> Wide|<|~ Last|\n|:--|:--|:--|\n|a|b|c|\n",
                "| Wide |  | Last |\n| ---: | :--- | :---: |\n| a | b | c |\n",
            ],
            'two covered columns with distinct defaults' => [
                "|= A|<|<|= D|\n|---|---:|:---:|:---|\n|a|b|c|d|\n",
                "| A |  |  | D |\n| --- | ---: | :---: | :--- |\n| a | b | c | d |\n",
            ],
            'covered column after another header cell' => [
                "|= A|= B|<|\n|:--|--:|:-:|\n|a|b|c|\n",
                "| A | B |  |\n| :--- | ---: | :---: |\n| a | b | c |\n",
            ],
        ];
    }

    #[DataProvider('tables')]
    public function testCoveredColumnsKeepTheirDelimiterAlignment(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::markdown(new BlockParser())->convert($source));
    }
}
