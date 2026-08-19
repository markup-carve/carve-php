<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The definition pre-pass and the `%%%` comment fence (carve-php#698).
 *
 * The pre-pass walks lines before the block parser runs, so it has to know
 * which of them are inside a comment. It did not, and four shapes fell out of
 * that - each one measured against carve-js, which resolves in every case
 * below except the unterminated one.
 */
final class CommentFencePrePassTest extends TestCase
{
    private function html(string $src): string
    {
        return CarveConverter::create()->convert($src);
    }

    private function assertResolves(string $src, string $message): void
    {
        $this->assertMatchesRegularExpression('/fnref|doc-noteref/', $this->html($src), $message);
    }

    public function testAColonFenceInsideACommentIsNotALineBlockOpener(): void
    {
        // The pre-pass entered line-block state on the commented opener, and a
        // comment's closer is not a colon fence - so the state never ended and
        // every later definition was skipped.
        $this->assertResolves(
            "%%%\n::: |\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a `::: |` inside a comment must not open a line block',
        );
    }

    public function testAnUnterminatedCommentFenceIsNotAFencedComment(): void
    {
        // The block parser degrades a lone `%%%` to a single-line comment, so
        // the line block below it is REAL and the definition inside it is verse
        // text. Entering comment state here would suppress every later line
        // block instead.
        $this->assertDoesNotMatchRegularExpression(
            '/fnref|doc-noteref/',
            $this->html("%%%\n\n::: |\n[^a]: note\n:::\n\nsee [^a]\n"),
            'an unterminated %%% must not open a fenced comment',
        );
    }

    public function testACommentCloserMayCarryTrailingText(): void
    {
        // `%%% end` closes a `%%%` fence: the closer test counts the leading
        // `%` run. Matching only a bare line left the comment open.
        $this->assertResolves(
            "%%% trailing\n::: |\n%%% end\n\n[^a]: note\n\nsee [^a]\n",
            '`%%% end` must close a `%%%` fence',
        );
    }

    public function testACodeFenceInsideACommentIsCommentText(): void
    {
        // Letting the fence reach the pre-pass fence scanner opened a code
        // block that swallowed the real comment closer.
        $this->assertResolves(
            "%%%\n```\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a code fence inside a comment must not open a code block',
        );
    }

    public function testACloserOfADifferentLengthDoesNotClose(): void
    {
        // Keyed by EXACT length: a `%%%%` line does not close a `%%%` fence, so
        // this comment runs to the `%%%` and the definition after it registers.
        $this->assertResolves(
            "%%%\n%%%%\n::: |\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a fence closes only on its own length',
        );
    }

    public function testADefinitionInsideACommentDoesNotRegister(): void
    {
        // The body is skipped outright. Intended: a comment renders nothing,
        // and carve-js has never registered from inside one.
        $this->assertDoesNotMatchRegularExpression(
            '/fnref|doc-noteref/',
            $this->html("%%%\n[^a]: note\n%%%\n\nsee [^a]\n"),
            'a definition inside a comment must not register',
        );
    }

    /**
     * PART 9 §24 S2 and §28 hide a comment's body WHEREVER the fence sits, and
     * the pre-pass read the opener at column 0 only. So a definition written at
     * a list item's content column registered while the block parser rendered
     * nothing: active in the link table, absent from the page
     * (markup-carve/carve#1311, corpus 335-339).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function containedCommentProvider(): array
    {
        return [
            'at the item content column' => ["- item\n  %%%\n  [r]: /url\n  %%%\n\n[r][]\n", '[r][]'],
            'on the item marker line' => ["- %%%\n  [r]: /url\n  %%%\n\n[r][]\n", '[r][]'],
            'one item deeper' => ["- a\n  - b\n    %%%\n    [r]: /url\n    %%%\n\n[r][]\n", '[r][]'],
            'a wider fence' => ["- item\n  %%%%\n  [r]: /url\n  %%%%\n\n[r][]\n", '[r][]'],
            'inside a colon container' => ["::: note\nBody.\n\n%%%\n[r]: /url\n%%%\n:::\n\n[r][]\n", '[r][]'],
        ];
    }

    #[DataProvider('containedCommentProvider')]
    public function testAContainedCommentRegistersNoLinkReference(string $source, string $literal): void
    {
        $html = $this->html($source);

        $this->assertStringContainsString($literal, $html, 'the reference must stay literal');
        $this->assertStringNotContainsString('href="/url"', $html, 'nothing may resolve against a commented definition');
    }

    public function testAContainedCommentRegistersNoFootnote(): void
    {
        // The footnote pre-pass reads the fence for itself, so widening the
        // link-reference one leaves this half behind (corpus 336).
        $this->assertDoesNotMatchRegularExpression(
            '/fnref|doc-noteref/',
            $this->html("- item\n  %%%\n  [^f]: note body\n  %%%\n\ntext[^f]\n"),
            'a footnote definition at an item content column must not register',
        );
    }

    public function testAnAbbreviationInsideACommentDefinesNothing(): void
    {
        // The abbreviation collector reaches its lines by a THIRD path - not
        // the link-reference pre-pass and not the footnote one - and tracked no
        // comment fence at all, so this defined an <abbr> for the whole
        // document from a line that renders nowhere (corpus 340).
        $this->assertStringNotContainsString(
            '<abbr',
            $this->html("%%%\n*[HTML]: HyperText Markup Language\n%%%\n\nHTML here\n"),
            'an abbreviation inside a comment must define nothing',
        );
    }

    public function testACloserBackAtColumnZeroDoesNotCloseAnItemScopedComment(): void
    {
        // Widening the opener ALONE is a worse defect than the one it fixes. An
        // item's comment is bounded by the item: the block parser ended the item
        // long before this `%%%` and reads the indented fence as an unterminated
        // one-line comment. Taking that far closer swallows the definition in
        // between (markup-carve/carve-rs#1052 landed the bound for this reason).
        $html = $this->html("- item\n  %%%\n  hidden\n\n[r]: /url\n\n%%%\n\n[r][]\n");

        $this->assertStringContainsString('href="/url"', $html, 'the column-0 definition must still register');
        $this->assertStringNotContainsString('[r]: /url', $html, 'and it must not leak as text');
    }

    public function testAMarkerLineThatOpensNoItemStillOpensItsComment(): void
    {
        // A bullet does not interrupt an open paragraph, so `- %%%` under
        // `paragraph` opens no item and `[r]: /url` under it renders as prose.
        // The pre-pass strips the marker anyway - it has no paragraph state and
        // cannot grow one - so the comment opens and the line defines nothing.
        //
        // That is the RIGHT half to give up. Reading the marker the other way
        // left the line VISIBLE and ACTIVE at once: the reader saw `[r]: /url`
        // in the paragraph while a reference below silently resolved through
        // it, which is the outcome no reading of the document produces
        // (the `VA` rows of carve#669 and carve#701). Prose that defines
        // nothing is a reading; prose that also defines is not.
        //
        // carve-rs at markup-carve/carve-rs#1052 - the change this ports - emits
        // these exact bytes for this document; it strips the marker with no
        // paragraph state either. Before this fix carve-php was the one engine
        // resolving the reference.
        $html = $this->html("paragraph\n- %%%\n  [r]: /url\n  %%%\n\n[r][]\n");

        $this->assertStringContainsString('[r]: /url', $html, 'the line renders as paragraph text');
        $this->assertStringNotContainsString('href="/url"', $html, 'and a line that renders may not also define');
    }

    public function testAPercentRunInsideAnIndentedLineBlockIsVerseNotACommentOpener(): void
    {
        // A line block's body is verse, so the `%%%` here is TEXT. Widening the
        // comment opener without asking that question first opened a comment on
        // it, and the pair of `%%%` runs then spanned the real `:::` closer and
        // the definition under it - so the definition registered nowhere while
        // the block parser still consumed it (carve#664's "rendered nowhere AND
        // defined nothing"). Both prepasses had it; the footnote one also had to
        // learn that an INDENTED `::: |` opens a line block at all.
        $reference = $this->html("- item\n  ::: |\n  %%%\n  text\n  :::\n  [r]: /url\n  %%%\n\n[r][]\n");
        $footnote = $this->html("- item\n  ::: |\n  %%%\n  text\n  :::\n  [^f]: note\n  %%%\n\ntext[^f]\n");

        $this->assertStringContainsString('href="/url"', $reference, 'the definition after the line block still registers');
        $this->assertMatchesRegularExpression('/fnref|doc-noteref/', $footnote, 'and so does the footnote form');
    }

    /**
     * A code sample's interior is verbatim (PART 9 §24 S2), so nothing in it
     * decides whether a definition ELSEWHERE is collected. This pass tested the
     * code fence LAST, after the comment and verse trackers, so a `%%%` shown
     * in one sample took the `%%%` in a later sample as its closer and the
     * opaque region swallowed the definition between them; a `::: |` shown in a
     * sample opened a verse region that did the same to the rest of the
     * document. carve-js had this from the same single cause. Reported as
     * markup-carve/carve-php#1349 item 1.
     *
     * @return array<string, array{0: string}>
     */
    public static function structureInsideASampleProvider(): array
    {
        $fence = str_repeat('`', 3);

        return [
            'a percent run in a sample takes no later sample as its closer' => [
                $fence . "\n%%%\n" . $fence . "\n\n[r]: /url\n\n" . $fence . "\n%%%\n" . $fence . "\n\n[r][]\n",
            ],
            'a colon-pipe in a sample opens no verse' => [
                $fence . "\n::: |\n" . $fence . "\n\n[r]: /url\n\n[r][]\n",
            ],
        ];
    }

    #[DataProvider('structureInsideASampleProvider')]
    public function testStructureShownInACodeSampleDecidesNothing(string $source): void
    {
        $this->assertStringContainsString(
            'href="/url"',
            $this->html($source),
            'a definition outside the sample must still register',
        );
    }

    public function testACommentFenceInsideACodeSampleInAnItemIsStillCode(): void
    {
        // The code fence is asked FIRST, so a `%%%` shown in a sample at an
        // item's content column is sample text rather than a comment opener -
        // and the definition below it is code, not a definition.
        $html = $this->html("- item\n  ```\n  %%%\n  [r]: /url\n  %%%\n  ```\n\n[r][]\n");

        $this->assertStringContainsString('[r][]', $html, 'the reference must stay literal');
        $this->assertStringNotContainsString('href="/url"', $html, 'a sample defines nothing');
    }
}
