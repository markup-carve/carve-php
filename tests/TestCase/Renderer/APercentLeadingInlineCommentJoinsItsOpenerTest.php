<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2a [CARVE-P11-008] names `| %%%` written as `| %% %` as a
 * counterexample: an opener run split into an opener plus a stray character.
 * So the inline arm joins a percent-leading content onto the marker
 * (carve#581, carve#544).
 *
 * The BLOCK arm deliberately does NOT, and the last two cases pin why: there a
 * run of three is a comment FENCE (PART 9 §28), so joining pairs two written
 * lines and swallows the body between them (carve-js#1675).
 */
class APercentLeadingInlineCommentJoinsItsOpenerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inlineSpellings(): array
    {
        return [
            'a verse line holds the run whole' => ["::: |\na\n%%%\nb\n:::\n", "::: |\na\n%%%\nb\n:::\n"],
            'a verse line canonicalizes the separated spelling' => ["::: |\na\n%% %\nb\n:::\n", "::: |\na\n%%%\nb\n:::\n"],
            'a trailing comment on a paragraph' => ["a %%%\n", "a %%%\n"],
            'the separated spelling canonicalizes to it' => ["a %% %\n", "a %%%\n"],
            'a longer run keeps its content' => ["a %%%foo\n", "a %%%foo\n"],
            'the content may carry a space of its own' => ["a %%% x\n", "a %%% x\n"],
            'a heading keeps the run whole' => ["# h %%%\n", "# h %%%\n"],
            'a quote keeps the run whole' => ["> q %%%\n", "> q %%%\n"],
            'a non-percent content keeps the separator' => ["a %% x\n", "a %% x\n"],
            'an empty content writes the marker alone' => ["a %%\n", "a %%\n"],
            'a delimited comment is untouched' => ["a {% % %}\n", "a {% % %}\n"],
            'a delimited comment ending in a percent is untouched' => ["a {% x% %}\n", "a {% x% %}\n"],
        ];
    }

    #[DataProvider('inlineSpellings')]
    public function testTheWrittenSpelling(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('inlineSpellings')]
    public function testTheWriterIsAFixedPoint(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($expected));
    }

    #[DataProvider('inlineSpellings')]
    public function testTheRoundTripKeepsTheRender(string $source, string $expected): void
    {
        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert(CarveConverter::toCarve($source)),
        );
    }

    #[DataProvider('inlineSpellings')]
    public function testTheCommentContentSurvives(string $source, string $expected): void
    {
        $this->assertSame($this->commentContents($source), $this->commentContents(CarveConverter::toCarve($source)));
    }

    public function testTheJoinedSpellingReadsBackTheSameContent(): void
    {
        $this->assertSame([['%']], $this->commentContents("a %%%\n"));
    }

    public function testTheSeparatedSpellingReadsBackTheSameContent(): void
    {
        $this->assertSame([['%']], $this->commentContents("a %% %\n"));
    }

    public function testANestedItemsBlockCommentKeepsItsSeparator(): void
    {
        $this->assertSame("- - x\n    %% %\n    y\n", CarveConverter::toCarve("- - x\n  %%%\n  y\n"));
    }

    public function testTheBlockArmKeepsItsSeparator(): void
    {
        $this->assertSame("%% %\n\nx\n\n%% %\n", CarveConverter::toCarve("%%%\nx\n%% %\n"));
    }

    public function testTheBlockArmNeverEmitsAJoinedLine(): void
    {
        $this->assertStringNotContainsString("\n%%%\n", "\n" . CarveConverter::toCarve("%%%\nx\n%% %\n"));
    }

    public function testTheParagraphBetweenTwoBlockCommentsSurvives(): void
    {
        $source = "%%%\nx\n%% %\n";
        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert(CarveConverter::toCarve($source)),
        );
    }

    public function testThatParagraphIsStillRendered(): void
    {
        $this->assertStringContainsString('<p>x</p>', $this->converter->convert(CarveConverter::toCarve("%%%\nx\n%% %\n")));
    }

    /**
     * @return array<array{string}>
     */
    private function commentContents(string $source): array
    {
        $found = [];
        $this->collect($this->converter->parse($source), $found);

        return $found;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<array{string}> $found
     */
    private function collect(Node $node, array &$found): void
    {
        if ($node instanceof Comment) {
            $found[] = [$node->getContent()];
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $found);
        }
    }
}
