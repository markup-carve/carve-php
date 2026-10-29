<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A QUOTE or a DIV opened inside a list item owns the run written under it, so
 * a non-definition line indented past its column stays that container's text.
 * A link definition past a hosted div's column uses its authored base.
 *
 * `rebaseOverindentedItemBlocks()` recognises a container at the minimum column
 * through `containerContentColumn()`, which answers for a list marker and a
 * definition body and returns null for a quote and a div. Neither reached that
 * branch, so the run under them was rebased to the minimum and opened a
 * heading out of a container the spec renders as text (carve-php#1892).
 *
 * THE ARBITER IS THE EXECUTABLE SPEC, not another engine and not a twin one
 * host out. Every row below was measured against
 * `spec/scripts/spec/{layout,html}.mjs` at markup-carve/carve `4296257a` AND at
 * the pinned `95fc3a04`; both revisions agree on every one, which is what makes
 * them defects rather than rulings this engine has not adopted.
 *
 * The reverted carve-php#1890 is why that sentence is here: it argued the same
 * shape from a top-level twin, the spec disagreed for the FOOTNOTE-BODY host,
 * and seven documents went right to wrong.
 * `testAFootnoteBodyStillConsumesItsDefinition` is that control.
 */
class AContainerInAnItemOwnsItsIndentedContentTest extends TestCase
{
    use RuleATodo;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * Each row is the item-hosted document and the top-level document it must
     * answer like. This engine already answered the top-level twins correctly
     * and both spec revisions agree with them; only the container path differed.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function containerProvider(): array
    {
        return [
            'a div holding an indented heading' => [
                "- a\n  ::: note\n   # h\n  :::\n\nafter\n",
                "::: note\n # h\n:::\n\nafter\n",
            ],
            'a div whose payload contains a blank line' => [
                "- a\n  ::: note\n   text\n\n   # h\n  :::\n\nafter\n",
                "::: note\n text\n\n # h\n:::\n\nafter\n",
            ],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testTheItemUsesItsOwnContentColumn(string $inItem, string $atTopLevel): void
    {
        $this->assertStringContainsString('<h1 id="h">h</h1>', $this->container($this->converter->convert($inItem)));
        $this->assertStringContainsString('<p># h</p>', $this->container($this->converter->convert($atTopLevel)));
    }

    #[DataProvider('containerProvider')]
    public function testTheIndentedLineReadsAtItsHostColumn(string $inItem, string $atTopLevel): void
    {
        $this->assertStringContainsString('<h1', $this->converter->convert($inItem));
        $this->assertStringNotContainsString('<h1', $this->converter->convert($atTopLevel));
    }

    public function testItemHostedDefinitionUsesItsAuthoredBase(): void
    {
        foreach ([':::', '::::'] as $fence) {
            $inItem = "- a\n  $fence note\n   [r]: /url\n  $fence\n\nSee [r][].\n";
            $atTopLevel = "$fence note\n [r]: /url\n$fence\n\nSee [r][].\n";

            self::assertStringContainsString('href="/url"', $this->converter->convert($inItem));
            self::assertStringNotContainsString('href="/url"', $this->converter->convert($atTopLevel));
        }
    }

    /**
     * THE CONTROL THE REVERTED carve-php#1890 EXISTS FOR. A footnote body is
     * NOT this shape: the spec CONSUMES a definition written in a container
     * there and resolves the reference. The host decides consumption; what is
     * uniform is only which column the line reaches. If this row ever flips to
     * text, #1890 has come back.
     */
    public function testAFootnoteBodyStillConsumesItsDefinition(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  ::: note\n   [r]: /url\n  :::\n\nSee [r][] and [^f].");

        $this->assertStringContainsString('href="/url"', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    /**
     * AN OVERINDENTED OPENER BELOW A QUOTE IN AN ITEM IS QUOTED TEXT, and both
     * spec revisions say so: a quote in an item with a four-column div fence
     * below it renders the
     * fence inside the quote's paragraph rather than as a block of its own.
     *
     * Codex review read the quote arm as swallowing a block it should rebase,
     * by analogy with the FOOTNOTE-BODY shape `QuoteFenceLinterTest` covers.
     * Measured, the item host answers the other way and this engine already
     * matched the spec exactly; the assertion below is the measurement, not the
     * analogy. Pinned so the arm cannot be widened past it later.
     */
    public function testAnOverindentedOpenerBelowAQuoteInAnItemIsQuotedText(): void
    {
        $html = $this->converter->convert("- a\n  > q\n      ::: &gt;\n      b\n      :::\n\nafter");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('<div', $html);
    }

    /**
     * A QUOTE IN AN ITEM RELEASES ITS DEFINITION. The answer moved in
     * markup-carve/carve#1921, and the pin has since advanced to `5bc9c5fb`,
     * where a definition in a quote's lazy run is classified before ownership
     * and consumed wherever the quote sits - the item host included, the same
     * as spec `main`. carve#1921's earlier divergence has closed, so this is a
     * defect the pin now agrees on, not a pin bump (markup-carve/carve-php#1908).
     */
    public function testAQuoteInAnItemConsumesItsDefinition(): void
    {
        $html = $this->converter->convert("- a\n  > q\n   [r]: /url\n\nSee [r][].");

        $this->assertStringNotContainsString('[r]: /url', $html);
        $this->assertStringContainsString('href="/url"', $html);
    }

    /**
     * The first quote/aside/div in a rendered document, whitespace-normalized.
     */
    protected function container(string $html): string
    {
        $matched = preg_match('/<(blockquote|aside|div)\b.*?<\/\1>/s', $html, $match);
        $this->assertSame(1, $matched, 'no container rendered');

        return trim((string)preg_replace('/\s+/', ' ', $match[0]));
    }
}
