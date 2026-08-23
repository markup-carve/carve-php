<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `[noparse]` means the enclosed text is literal, and a line's OPENER is part
 * of that.
 *
 * `escapePlainBbcodeText()` escapes what a line HOLDS, and nothing escaped what
 * a line STARTS with, so `[noparse]` was the one place in this converter where
 * a line-initial `- ` reached the document live: text the source declared
 * literal came back as a list, and the blank run between two such lines was
 * then read as the hard list boundary of PART 9 §11 N1a, making two of them
 * (carve-php#1622).
 *
 * The code family needed no such escape because its body lands inside a fence,
 * which neutralizes everything; this one lands bare. PART 11 §2 is the rule:
 * escape a character if and only if omitting the escape would change the
 * re-parsed AST.
 */
class ANoparseBodyIsLiteralAtTheBlockLevelTooTest extends TestCase
{
    protected BbcodeToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new BbcodeToCarve();
    }

    protected function renderedText(string $bbcode): string
    {
        $html = (new CarveConverter())->convert($this->converter->convert($bbcode));
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\n{2,}/', "\n", $text) ?? $text);
    }

    public function testTheTicketsBodyComesBackAsItsOwnText(): void
    {
        $bbcode = "[noparse]\n- a\n\n\n\n- b\n[/noparse]\n";

        // The blank lines around the body are the newlines the tags sat on;
        // this converter has never trimmed the [noparse] family and that is
        // not what this ticket rules on.
        $this->assertSame("\n\\- a\n\n\n\n\\- b\n\n", $this->converter->convert($bbcode));
        $this->assertSame(
            "<p>- a</p>\n<p>- b</p>",
            trim((new CarveConverter())->convert($this->converter->convert($bbcode))),
        );
    }

    /**
     * Every line-initial opener Carve reads, round-tripped: escape it, render
     * it, and the visible text has to be the literal line the source held.
     *
     * @return array<string, array{0: string}>
     */
    public static function openerProvider(): array
    {
        $tick = chr(96);

        return [
            'hyphen bullet' => ['- a'],
            'asterisk bullet' => ['* a'],
            'decimal marker' => ['1. a'],
            'multi-digit paren marker' => ['12) a'],
            'alpha marker' => ['a. a'],
            'heading' => ['# h'],
            'deepest heading' => ['###### h'],
            'block quote' => ['> q'],
            'table row' => ['| a | b |'],
            'comment' => ['%% c'],
            'hyphen rule' => ['---'],
            'asterisk rule' => ['***'],
            'underscore rule' => ['___'],
            'spaced rule' => ['- - -'],
            'tilde fence' => ['~~~'],
            'div fence' => [':::: note'],
            'colon fence' => [':::'],
            'definition term' => [':: term'],
        ];
    }

    #[DataProvider('openerProvider')]
    public function testALineInitialOpenerComesBackAsItsOwnText(string $line): void
    {
        $this->assertSame($line, $this->renderedText("[noparse]\n{$line}\n[/noparse]\n"));
    }

    /**
     * A CODE FENCE NEEDS ITS PAIR to open anything, so it is pinned as a pair.
     * A single opener is already text under the unterminated-fence rule, and a
     * body that only ever showed the opener would pin nothing.
     */
    public function testAFencePairInTheBodyIsText(): void
    {
        $fence = str_repeat(chr(96), 3);
        $body = $fence . "php\nx\n" . $fence;

        $this->assertSame($body, $this->renderedText("[noparse]\n{$body}\n[/noparse]\n"));
    }

    // ==================== The bounds ====================

    /**
     * THE SAME TEXT OUTSIDE `[noparse]` IS UNTOUCHED. This is the whole hazard:
     * a converter that escapes every line-initial marker in a document puts a
     * backslash in front of every bullet the author wrote. The escape is
     * reached only from the `[noparse]` stash.
     */
    public function testTheSameTextOutsideNoparseIsStillAList(): void
    {
        $this->assertSame("- a\n\n- b\n", $this->converter->convert("- a\n\n\n\n- b\n"));
    }

    public function testAConvertedListIsNotEscaped(): void
    {
        $this->assertSame("- one\n- two\n", $this->converter->convert("[list]\n[*]one\n[*]two\n[/list]\n"));
    }

    /**
     * A LINE THAT OPENS NO BLOCK GAINS NO BACKSLASH. Asserted on the SOURCE: a
     * needless escape renders the same visible text and would pass a
     * render-only check while leaving a backslash the author has to delete.
     *
     * @return array<string, array{0: string}>
     */
    public static function untouchedProvider(): array
    {
        return [
            'prose' => ['plain text'],
            'leading hyphen inside a word' => ['not-a-marker'],
            'a decimal number' => ['3.14 is pi'],
            'a greater-than mid-line' => ['a > b'],
            'a bullet character with no space' => ['-a'],
            // Carve reads neither of these as a block opener, so neither may
            // gain a backslash - the Markdown and Djot habit of spelling both
            // is exactly how this rule would grow past what it is for.
            'a plus, which is not a Carve bullet' => ['+ a'],
            'a colon line, which is not a Carve definition item' => [': term'],
        ];
    }

    #[DataProvider('untouchedProvider')]
    public function testALineThatOpensNoBlockIsUntouched(string $line): void
    {
        $this->assertSame($line, trim($this->converter->convert("[noparse]\n{$line}\n[/noparse]\n")));
    }

    /**
     * A CODE BODY IS NOT ESCAPED. It lands inside a fence, where a backslash
     * would be content rather than an escape.
     */
    public function testACodeBodyKeepsItsMarkersBare(): void
    {
        $fence = str_repeat(chr(96), 3);

        $this->assertSame(
            $fence . "\n- a\n- b\n" . $fence . "\n",
            $this->converter->convert("[code]\n- a\n- b\n[/code]\n"),
        );
    }

    /**
     * THE INLINE ESCAPE STILL HAPPENS ONCE. The body was escaped by
     * escapePlainBbcodeText() before it was stashed, and escaping it again
     * doubled the backslash - a literal backslash plus the markup it was meant
     * to prevent (carve-php#1209).
     */
    public function testInlineSyntaxIsStillEscapedExactlyOnce(): void
    {
        $this->assertSame("\\*not bold*\n", $this->converter->convert('[noparse]*not bold*[/noparse]'));
    }
}
