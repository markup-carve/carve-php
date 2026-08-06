<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The colon fence's separator is a literal space; its metadata slots are not.
 *
 * `resources/grammar.ebnf` PART 7, MARKER SEPARATORS AND PADDING SLOTS, is
 * normative and splits the opener line into two roles that must NOT be swept
 * together (carve#878 step 2, spec edit carve#886):
 *
 * - The slot immediately after the fence run is a MARKER SEPARATOR: `space`,
 *   U+0020 only, because the token after it selects which of the four blocks
 *   the line opens.
 * - The admonition opener's title and label slots are PADDING: `whitespace`,
 *   which the grammar defines as a space or a tab and nothing else.
 *
 * Both were wrong here, in opposite directions: the separator admitted a tab,
 * and the metadata slots used the regex whitespace class, which in PCRE also
 * admits a form feed, a vertical tab and a carriage return.
 */
class ColonFenceSeparatorIsASpaceTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openerTokenProvider(): array
    {
        return [
            'admonition' => ['note'],
            'div label' => ['[lbl]'],
            'line block' => ['|'],
            'local hard break' => ['\\'],
        ];
    }

    #[DataProvider('openerTokenProvider')]
    public function testATabAfterTheFenceRunOpensNothing(string $token): void
    {
        // Asserted as "the opener line survives as text" rather than "there is
        // a paragraph": a div and a line block both WRAP a paragraph, so a
        // paragraph check passes for a container that should not have opened.
        $out = $this->html(":::\t{$token}\nx\n:::\n");

        $this->assertStringContainsString(':::', $out);
        $this->assertStringNotContainsString('<aside', $out);
        $this->assertStringNotContainsString('<div', $out);
    }

    #[DataProvider('openerTokenProvider')]
    public function testASpaceThereStillOpensIt(string $token): void
    {
        // The control per row: narrowing the class must not close the door on
        // the spelling the grammar does admit.
        $this->assertNotSame(
            $this->html("::: {$token}\nx\n:::\n"),
            $this->html(":::\t{$token}\nx\n:::\n"),
        );
    }

    public function testATabBeforeTheTitleIsPadding(): void
    {
        $this->assertStringContainsString('admonition-title', $this->html("::: note\t\"Title\"\nx\n:::\n"));
    }

    public function testATabBeforeTheLabelIsPaddingToo(): void
    {
        $this->assertStringContainsString('admonition-title', $this->html("::: note\t\"T\"\t[lbl]\nx\n:::\n"));
    }

    public function testTheSpaceSpellingIsUnchanged(): void
    {
        $this->assertStringContainsString('admonition-title', $this->html("::: note \"Title\"\nx\n:::\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonWhitespacePaddingProvider(): array
    {
        return [
            'form feed' => ["\f"],
            'vertical tab' => ["\v"],
            'carriage return' => ["\r"],
        ];
    }

    /**
     * `whitespace` is a space or a tab, exhaustively. The slots were spelled
     * with the regex whitespace class, which admits more than the grammar names.
     */
    #[DataProvider('nonWhitespacePaddingProvider')]
    public function testOnlyASpaceOrTabPads(string $ws): void
    {
        $this->assertStringNotContainsString('admonition-title', $this->html("::: note{$ws}\"Title\"\nx\n:::\n"));
    }
}
