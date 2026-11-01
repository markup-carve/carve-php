<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableReferenceEscapedPipeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function references(): array
    {
        return [
            'unresolved link text' => ['| [a\\|b][m] |', '[a|b][m]'],
            'unresolved image alt' => ['| ![a\\|b][m] |', '![a|b][m]'],
            'unresolved reference label' => ['| [x][r\\|s] |', '[x][r|s]'],
            'resolved reference label' => ["| [x][r\\|s] |\n\n[r|s]: /u", '<a href="/u">x</a>'],
            'backticks in a reference label stay literal' => ["| [x][`r\\|s`] |\n\n[`r|s`]: /u", '[x][`r\\|s`]'],
            'unmatched backtick in a label stays literal' => ["| [x][r` s\\|t] |\n\n[r` s|t]: /u", '[x][r` s\\|t]'],
            'paired backslash before code run' => ["| [x][r\\\\`a\\|b`] |\n\n[r\\\\`a|b`]: /u", '[x][r\\\\`a\\|b`]'],
            'backtick closes a code run past a slash' => ["| [x][`a\\`b\\|c`] |\n\n[`a\\`b|c`]: /u", '<a href="/u">x</a>'],
            'code span keeps its backslash' => ['| [a `x\\|y`][m] |', '[a `x\\|y`][m]'],
            'plain escaped pipe' => ['| a\\|b |', 'a|b'],
        ];
    }

    #[DataProvider('references')]
    public function testTableSplittingResolvesThePipeBeforeReferenceUse(string $source, string $cellHtml): void
    {
        $converter = CarveConverter::create();
        $html = $converter->convert($source);

        self::assertStringContainsString('<td>' . $cellHtml . '</td>', $html);
        self::assertSame($html, $converter->convert($converter->toCarve($source)));
    }
}
