<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnEmptyMarkdownHeaderDoesNotBecomeAParagraphTest extends TestCase
{
    public function testAHeaderlessTableRoundTrips(): void
    {
        $source = "| 1 | 2 |\n| 3 | 4 |\n";
        $markdown = CarveConverter::markdown()->convert($source);
        $this->assertSame("|  |  |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n", $markdown);
        $imported = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame($source, $imported);
        $this->assertSame($this->html($source), $this->html($imported));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function containers(): array
    {
        return [
            'document' => ["| | |\n| --- | --- |\n| 1 | 2 |\n"],
            'quote' => ["> | | |\n> | --- | --- |\n> | 1 | 2 |\n"],
            'nested quote' => ["> > | | |\n> > | --- | --- |\n> > | 1 | 2 |\n"],
            'item first block' => ["- | | |\n  | --- | --- |\n  | 1 | 2 |\n"],
            'item later block' => ["- text\n\n  | | |\n  | --- | --- |\n  | 1 | 2 |\n"],
            'quoted item later block' => ["> - text\n>\n>   | | |\n>   | --- | --- |\n>   | 1 | 2 |\n"],
            'quoted item' => ["> - | | |\n>   | --- | --- |\n>   | 1 | 2 |\n"],
            'quote in item' => ["- > | | |\n  > | --- | --- |\n  > | 1 | 2 |\n"],
        ];
    }

    #[DataProvider('containers')]
    public function testTheBodyKeepsItsContainer(string $markdown): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);
        $html = $this->html($imported);
        $this->assertStringNotContainsString('|=', $html);
        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringContainsString('<tr><td>1</td><td>2</td></tr>', $html);
        $this->assertSame(str_contains($markdown, '>'), str_contains($html, '<blockquote>'));
        $this->assertSame(preg_match('/^(?:> )*- /m', $markdown) === 1, str_contains($html, '<ul>'));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAnItemTableRoundTripsWithoutAddingAComment(): void
    {
        foreach (
            [
                "- | 1 | 2 |\n  | 3 | 4 |\n",
                "1. | 1 | 2 |\n   | 3 | 4 |\n",
                "> - | 1 | 2 |\n>   | 3 | 4 |\n",
                "- > | 1 | 2 |\n  > | 3 | 4 |\n",
            ] as $source
        ) {
            $markdown = CarveConverter::markdown()->convert($source);
            $this->assertSame($source, (new MarkdownToCarve())->convert($markdown));
        }
    }

    public function testAnOmittedTableKeepsItsQuote(): void
    {
        foreach (
            [
                "> | | |\n> | --- | --- |\n",
                "> > | | |\n> > | --- | --- |\n",
                "- > | | |\n  > | --- | --- |\n",
            ] as $markdown
        ) {
            $imported = (new MarkdownToCarve())->convert($markdown);
            $this->assertStringContainsString('<blockquote>', $this->html($imported));
            $this->assertStringNotContainsString('|', $this->html($imported));
        }
    }

    public function testAnAlignmentOrContentKeepsTheHeader(): void
    {
        foreach (
            [
                "| | |\n| :--- | ---: |\n| 1 | 2 |\n",
                "| A | |\n| --- | --- |\n| 1 | 2 |\n",
            ] as $markdown
        ) {
            $html = $this->html((new MarkdownToCarve())->convert($markdown));
            $this->assertStringContainsString('<thead>', $html);
            $this->assertStringNotContainsString('<p>|', $html);
        }
    }

    public function testBlankBodyRowsDoNotSplitTheTable(): void
    {
        $markdown = "| A | B |\n| --- | --- |\n| 1 | 2 |\n| | |\n| 3 | 4 |\n";
        $imported = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame("|= A |= B |\n| 1 | 2 |\n| 3 | 4 |\n", $imported);
        $this->assertSame(1, substr_count($this->html($imported), '<table>'));
    }

    public function testAHeaderWithNoBodyLeavesNoPipeParagraph(): void
    {
        foreach (
            [
                "| | |\n| --- | --- |\n",
                "> | | |\n> | --- | --- |\n",
                "- | | |\n  | --- | --- |\n",
                "> - | | |\n>   | --- | --- |\n",
            ] as $markdown
        ) {
            $imported = (new MarkdownToCarve())->convert($markdown);
            $this->assertStringNotContainsString('|', $this->html($imported));
            $this->assertStringNotContainsString('<table>', $this->html($imported));
            if ($imported !== '') {
                $this->assertSame($imported, CarveConverter::toCarve($imported));
            }
        }
    }

    public function testAnOmittedTableKeepsAdjacentListsSeparate(): void
    {
        foreach (['', '> '] as $prefix) {
            $markdown = implode("\n", array_map(
                static fn (string $line): string => $prefix . $line,
                ['- a', '', '| | |', '| --- | --- |', '', '- b', ''],
            ));
            $imported = (new MarkdownToCarve())->convert($markdown);
            $html = $this->html($imported);
            $this->assertSame(2, substr_count($html, '<ul>'));
            $this->assertStringContainsString('<li>a</li>', $html);
            $this->assertStringContainsString('<li>b</li>', $html);
            $this->assertStringNotContainsString('|', $html);
            $this->assertStringNotContainsString('CARVE_OMITTED_TABLE', $imported);
            $this->assertSame($imported, CarveConverter::toCarve($imported));
        }
    }

    public function testAnOmittedTableLeavesCanonicalParagraphBoundaries(): void
    {
        $converter = new MarkdownToCarve();
        $this->assertSame("para\n", $converter->convert("| |\n| --- |\n\npara\n"));
        $this->assertSame("> a\n>\n> b\n", $converter->convert("> a\n>\n> | |\n> | --- |\n>\n> b\n"));
    }

    public function testTheTemporaryCommentCannotConsumeAuthoredContent(): void
    {
        $source = "CARVE_OMITTED_TABLE\n\n| |\n| --- |\n";
        $this->assertSame("CARVE_OMITTED_TABLE\n", (new MarkdownToCarve())->convert($source));
    }

    public function testImportsWithDroppedRowsUseCanonicalFormatting(): void
    {
        $markdown = "*x*\\_y\n\n| |\n| --- |\n";
        $imported = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame("{/x/}\\_y\n", $imported);
        $withoutTable = (new MarkdownToCarve())->convert("*x*\\_y\n");
        $this->assertSame($this->html($withoutTable), $this->html($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testADroppedHeaderReportsItsSourceLine(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("text\n\n| | |\n| --- | --- |\n| 1 | 2 |\n");
        $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
        $this->assertCount(1, $rows);
        $this->assertSame('line:3', $rows[0]->path);
        $this->assertSame('dropped', $rows[0]->fidelity);
    }

    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }
}
