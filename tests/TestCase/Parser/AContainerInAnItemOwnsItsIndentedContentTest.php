<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A QUOTE or a DIV opened inside a list item owns the run written under it, so
 * a line indented past its column stays that container's text instead of being
 * given an authored base.
 *
 * `rebaseOverindentedItemBlocks()` recognises a container at the minimum column
 * through `containerContentColumn()`, which answers for a list marker and a
 * definition body and returns null for a quote and a div. Neither reached that
 * branch, so the run under them was rebased to the minimum - which OPENED a
 * heading, and CONSUMED a definition, out of a container the spec renders as
 * text (carve-php#1892).
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
            'a div holding an indented definition' => [
                "- a\n  ::: note\n   [r]: /url\n  :::\n\nSee [r][].\n",
                "::: note\n [r]: /url\n:::\n\nSee [r][].\n",
            ],
            'a div holding an indented heading' => [
                "- a\n  ::: note\n   # h\n  :::\n\nafter\n",
                "::: note\n # h\n:::\n\nafter\n",
            ],
            'a div whose payload contains a blank line' => [
                "- a\n  ::: note\n   text\n\n   # h\n  :::\n\nafter\n",
                "::: note\n text\n\n # h\n:::\n\nafter\n",
            ],
            'a wide div holding an indented definition' => [
                "- a\n  :::: note\n   [r]: /url\n  ::::\n\nSee [r][].\n",
                ":::: note\n [r]: /url\n::::\n\nSee [r][].\n",
            ],
        ];
    }

    /**
     * The container renders the same whether it sits at the top level or two
     * columns in, compared as a normalized fragment because the item nests it
     * one level deeper.
     *
     * A DIV'S EXTENT IS READ BY `colonFenceEnd()`, the reader the parser itself
     * uses, so it survives a blank line - a div stays open across one, and a
     * skip that stopped at the first blank handed the rest back. A QUOTE's run
     * ENDS at a blank, which is why the two are separate arms.
     */
    #[DataProvider('containerProvider')]
    public function testTheItemAnswersLikeTheTopLevel(string $inItem, string $atTopLevel): void
    {
        $this->assertSame(
            $this->container($this->converter->convert($atTopLevel)),
            $this->container($this->converter->convert($inItem)),
        );
    }

    /**
     * AND THE LINE STAYS INERT. A definition that renders is not a definition,
     * so nothing may resolve through it; a heading that renders as text opens
     * no `h1`. Without this the row above is satisfied by any rule that makes
     * the two hosts agree, including one that breaks both.
     */
    #[DataProvider('containerProvider')]
    public function testTheIndentedLineIsTextAndInert(string $inItem, string $atTopLevel): void
    {
        foreach ([$inItem, $atTopLevel] as $source) {
            $html = $this->converter->convert($source);

            $this->assertStringNotContainsString('<h1', $html);
            $this->assertStringNotContainsString('href="/url"', $html);
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
     * A QUOTE IN AN ITEM IS DELIBERATELY ABSENT from the provider. Its answer
     * moved in markup-carve/carve#1921, so this engine tracks the PINNED
     * revision and differs from spec `main` there; it is a pin bump, not a
     * defect, and asserting either answer here would pin the wrong one.
     */
    public function testAQuoteInAnItemIsLeftToTheSpecPin(): void
    {
        $html = $this->converter->convert("- a\n  > q\n   [r]: /url\n\nSee [r][].");

        $this->assertStringContainsString('[r]: /url', $html);
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
