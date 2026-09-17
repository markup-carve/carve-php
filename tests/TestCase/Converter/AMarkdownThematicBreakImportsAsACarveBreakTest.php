<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every spelling CommonMark reads as a thematic break imports as source Carve
 * reads as one, spelled the way the writer spells the node
 * (markup-carve/carve-php#2097).
 */
class AMarkdownThematicBreakImportsAsACarveBreakTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function breakProvider(): array
    {
        return [
            'a spaced hyphen run' => ['- - -'],
            'a spaced asterisk run' => ['* * *'],
            'a spaced underscore run' => ['_ _ _'],
            'a widely spaced run' => ['*  *  *'],
            'a tab separated run' => ["*\t*\t*"],
            'a spaced run longer than three' => ['- - - -'],
            'a hyphen run longer than three' => ['-----'],
            'an asterisk run longer than three' => ['*****'],
            'an underscore run longer than three' => ['_____'],
            'one column of indent' => [' ---'],
            'two columns of indent' => ['  ***'],
            'three columns of indent' => ['   ___'],
            'a trailing space' => ['--- '],
            'trailing spaces' => ['***  '],
            'a trailing tab' => ["___\t"],
            'an indented spaced run' => ['   * * *'],
            'a contiguous hyphen run' => ['---'],
            'a contiguous asterisk run' => ['***'],
            'a contiguous underscore run' => ['___'],
        ];
    }

    /**
     * The writer's own bytes are the expectation, so the test cannot pin a
     * spelling the writer would not produce.
     */
    #[DataProvider('breakProvider')]
    public function testTheBreakIsWrittenAsTheWriterSpellsIt(string $line): void
    {
        $document = new Document();
        $document->appendChild(new ThematicBreak());
        $written = rtrim((new CarveRenderer())->render($document), "\n");

        $imported = (new MarkdownToCarve())->convert("a\n\n" . $line . "\n\nb");

        $this->assertSame("a\n\n" . $written . "\n\nb", $imported);
        $this->assertStringContainsString('<hr>', (new CarveConverter())->convert($imported));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listProvider(): array
    {
        return [
            'a hyphen list' => ["- a\n- b"],
            'an asterisk list' => ["* a\n* b"],
            'two hyphens' => ['- -'],
            'two asterisks' => ['* *'],
            'mixed markers' => ['- * -'],
            'a plus run' => ['+ + +'],
        ];
    }

    #[DataProvider('listProvider')]
    public function testASpellingThatIsAListStaysAList(string $lines): void
    {
        $imported = (new MarkdownToCarve())->convert("a\n\n" . $lines . "\n\nb");

        $html = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringNotContainsString('<hr>', $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function containerProvider(): array
    {
        return [
            'in a list item' => ["- a\n\n  * * *", "- a\n\n  ---"],
            'on an item continuation line' => ["- a\n  - - -", "- a\n  ---"],
            'in a quote' => ["> a\n>\n> * * *", "> a\n> \n> ---"],
            'abutting the quote marker' => ['>* * *', '> ---'],
            'closing the list it dedents past' => ["- x\n- - -", "- x\n---"],
        ];
    }

    /**
     * A break keeps its container's content column, and it outranks the list
     * marker its own spelling looks like.
     */
    #[DataProvider('containerProvider')]
    public function testTheBreakKeepsItsContainer(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertStringContainsString('<hr>', (new CarveConverter())->convert($imported));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function afterProvider(): array
    {
        return [
            'an empty item' => ["***\n-", "---\n- +"],
            'indented code' => ["***\n    code", "---\n```\ncode\n```"],
            'a heading' => ["***\n# h", "---\n# h"],
            'an ordered item that does not start at one' => ["***\n2. x", "---\n2. x"],
        ];
    }

    /**
     * A break closes every open block, so the line below it opens one of its
     * own rather than continuing a paragraph.
     */
    #[DataProvider('afterProvider')]
    public function testTheLineBelowTheBreakOpensItsOwnBlock(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * A line inside an open raw-HTML block is literal content, so the run it
     * holds is not a break and nothing respells it.
     */
    public function testAnOpenRawHtmlBlockKeepsItsLiteralRun(): void
    {
        $imported = (new MarkdownToCarve(convertRawHtml: true))->convert("<script>\n* * *\nx");

        $this->assertStringContainsString('* * *', $imported);
    }

    /**
     * The document from the ticket, end to end.
     */
    public function testTheRuleSurvivesAsARule(): void
    {
        $imported = (new MarkdownToCarve())->convert("a\n\n* * *\n\nb");

        $this->assertSame("a\n\n---\n\nb", $imported);
        $this->assertSame(
            "<p>a</p>\n<hr>\n<p>b</p>\n",
            (new CarveConverter())->convert($imported),
        );
    }
}
