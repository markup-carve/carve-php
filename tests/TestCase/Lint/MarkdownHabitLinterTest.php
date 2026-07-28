<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownHabitLinterTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function rules(string $source): array
    {
        return array_map(
            static fn ($warning): string => $warning->rule,
            (new MarkdownHabitLinter())->lint($source),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function habitProvider(): array
    {
        return [
            'doubled asterisks' => ['**bold**', MarkdownHabitLinter::RULE_STRONG_ASTERISKS],
            'doubled underscores' => ['__bold__', MarkdownHabitLinter::RULE_STRONG_UNDERSCORES],
            'doubled tildes' => ['~~struck~~', MarkdownHabitLinter::RULE_STRIKETHROUGH],
        ];
    }

    #[DataProvider('habitProvider')]
    public function testReportsTheHabit(string $source, string $rule): void
    {
        $this->assertSame([$rule], $this->rules($source));
    }

    /**
     * The lint claims these render as literal punctuation. If that ever stops
     * being true the diagnostic becomes a lie, so it is pinned here.
     */
    #[DataProvider('habitProvider')]
    public function testTheReportedFormReallyRendersLiterally(string $source, string $rule): void
    {
        $this->assertNotSame('', $rule);
        $this->assertSame(
            "<p>{$source}</p>\n",
            (new CarveConverter())->convert($source),
        );
    }

    public function testTheSuggestedReplacementsAreCorrectCarve(): void
    {
        $converter = new CarveConverter();

        $this->assertSame("<p><strong>bold</strong></p>\n", $converter->convert('*bold*'));
        $this->assertSame("<p><s>struck</s></p>\n", $converter->convert('~struck~'));
    }

    public function testCorrectCarveIsNeverReported(): void
    {
        // `*x*` is strong and `_x_` is underline in Carve. Warning on them would
        // punish authors writing the language properly, so they are not rules.
        $this->assertSame([], $this->rules('*strong* and _underline_ and ~strike~'));
    }

    public function testVerbatimSpansAreSkipped(): void
    {
        $this->assertSame([], $this->rules('`**not bold**` and ``~~not struck~~``'));
    }

    public function testFencedBlocksAreSkipped(): void
    {
        $source = "```md\n**bold** and ~~struck~~\n```\n";

        $this->assertSame([], $this->rules($source));
    }

    public function testReportsAHeadingThatSwallowsTheNextLine(): void
    {
        $this->assertSame(
            [MarkdownHabitLinter::RULE_HEADING_LAZY_CONTINUATION],
            $this->rules("### Title\nBody line\n"),
        );
    }

    public function testTheSwallowedHeadingReallySwallows(): void
    {
        // The claim the diagnostic makes, pinned against the renderer: the
        // following line lands INSIDE the heading, which the derived id shows
        // most plainly.
        $html = (new CarveConverter())->convert("### Title\nBody line\n");

        $this->assertStringContainsString('id="Title-Body-line"', $html);
        $this->assertStringContainsString("<h3>Title\nBody line</h3>", $html);
    }

    public function testABlankLineAfterTheHeadingIsClean(): void
    {
        $this->assertSame([], $this->rules("### Title\n\nBody line\n"));
    }

    public function testAHeadingEndingTheDocumentIsClean(): void
    {
        $this->assertSame([], $this->rules("### Title\n"));
    }

    public function testAHeadingFollowedByAnotherBlockOpenerIsClean(): void
    {
        $this->assertSame([], $this->rules("### Title\n### Next\n"));
        $this->assertSame([], $this->rules("### Title\n```php\necho 1;\n```\n"));
    }

    public function testWarningCarriesPositionAndOffsets(): void
    {
        $source = "intro\n\nsee **bold** here\n";
        $warnings = (new MarkdownHabitLinter())->lint($source);

        $this->assertCount(1, $warnings);
        $warning = $warnings[0];
        $this->assertSame(3, $warning->line);
        $this->assertSame(5, $warning->column);
        $this->assertSame('**bold**', substr($source, $warning->start, $warning->end - $warning->start));
    }

    public function testEveryHabitOnOneLineIsReportedInColumnOrder(): void
    {
        $this->assertSame(
            [
                MarkdownHabitLinter::RULE_STRONG_ASTERISKS,
                MarkdownHabitLinter::RULE_STRONG_UNDERSCORES,
                MarkdownHabitLinter::RULE_STRIKETHROUGH,
            ],
            $this->rules('**a** __b__ ~~c~~'),
        );
    }

    public function testACleanCarveDocumentProducesNothing(): void
    {
        $source = "# Title\n\nA *strong* claim with a [link](https://example.com).\n\n- one\n- two\n";

        $this->assertSame([], $this->rules($source));
    }
}
