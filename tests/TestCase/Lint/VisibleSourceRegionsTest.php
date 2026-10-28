<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibleSourceRegionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function protectedSource(): iterable
    {
        yield 'emphasis byte in destination' => ['[pre <http://h> post](/u)', 'unclosed-inline-emphasis', 0];
        yield 'tag in destination' => ['[x](a<g>b)', 'bare-html-tag', 0];
        yield 'tag in quoted code fence' => ["- a\n  > q\n   ```=html\n   <b>r</b>\n   ```\n", 'bare-html-tag', 0];
        yield 'tag outside inline code' => ['`<b>` <i>', 'bare-html-tag', 1];
    }

    #[DataProvider('protectedSource')]
    public function testSourcePatternsUseRenderedText(string $source, string $rule, int $expected): void
    {
        $warnings = array_values(array_filter(
            (new AuthoringLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === $rule,
        ));

        self::assertCount($expected, $warnings);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function destinations(): iterable
    {
        yield 'empty' => ['[x]()', 1];
        yield 'whitespace' => ['[x](   )', 1];
        yield 'nonempty' => ['[x](/u)', 0];
        yield 'reference' => ['[x][]', 0];
        yield 'escaped parenthesis' => ['[x]\\()', 0];
    }

    #[DataProvider('destinations')]
    public function testEmptyDestinationRequiresAParsedInlineLink(string $source, int $expected): void
    {
        $warnings = array_values(array_filter(
            (new AuthoringLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'empty-link-destination',
        ));

        self::assertCount($expected, $warnings);
        if ($expected > 0) {
            self::assertSame([1, 1], [$warnings[0]->line, $warnings[0]->column]);
        }
    }

    public function testTabAfterTermSigilLeavesDefinitionShapedParagraphText(): void
    {
        $warnings = (new AuthoringLinter())->lint("::\tterm\n:  d\n");
        $matches = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'markdown-definition-paragraph'));

        self::assertCount(1, $matches);
        self::assertSame([2, 1], [$matches[0]->line, $matches[0]->column]);
    }

    public function testFormFeedAfterPipeDoesNotCloseATableRow(): void
    {
        $warnings = (new AuthoringLinter())->lint("|a|b|\f\n");
        $matches = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'table-shaped-paragraph'));

        self::assertCount(1, $matches);
        self::assertSame([1, 1], [$matches[0]->line, $matches[0]->column]);
    }

    #[DataProvider('tableMarkerLines')]
    public function testTableShapedParagraphOnAListMarkerLine(string $source, int $column): void
    {
        $warnings = (new AuthoringLinter())->lint($source);
        $matches = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'table-shaped-paragraph'));

        self::assertCount(1, $matches);
        self::assertSame($column, $matches[0]->column);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function tableMarkerLines(): iterable
    {
        yield 'bullet' => ["- |a|b|\f\n", 3];
        yield 'task' => ["* [ ] |a|b|\f\n", 7];
        yield 'ordered' => ["12. |a|b|\f\n", 5];
        yield 'quote and bullet' => ["> - |a|b|\f\n", 5];
    }
}
