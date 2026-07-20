<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline literal (`` `content`{!} ``, grammar PART 9 §27).
 *
 * A code span whose trailing attribute block starts with the `!` sigil: the
 * verbatim content is HTML-escaped and emitted by every renderer (never
 * dropped / target-routed like raw inline), with the `<code>` wrapper removed.
 * With no further attribute it is bare escaped text; with any it is a `<span>`.
 */
class InlineLiteralTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    // ==================== HTML semantics ====================

    public function testBareEscapedTextWhenNoFurtherAttribute(): void
    {
        $this->assertSame('<p>/kaet/</p>', $this->html('`/kaet/`{!}'));
    }

    public function testSpanCarryingClass(): void
    {
        $this->assertSame('<p><span class="ipa">/kaet/</span></p>', $this->html('`/kaet/`{! .ipa}'));
    }

    public function testSpanCarryingClassAndIdInSourceOrder(): void
    {
        $this->assertSame('<p><span class="ipa" id="cat">/kaet/</span></p>', $this->html('`/kaet/`{! .ipa #cat}'));
    }

    public function testMixedAttributesRenderInSourceOrder(): void
    {
        $this->assertSame('<p><span class="a" id="b" k="v">x</span></p>', $this->html('`x`{! .a #b k=v}'));
        // ... and the reverse source order flips the emitted order.
        $this->assertSame('<p><span k="v" id="b" class="a">x</span></p>', $this->html('`x`{! k=v #b .a}'));
    }

    public function testHtmlEscapesContent(): void
    {
        $this->assertSame('<p>a&lt;b&gt;</p>', $this->html('`a<b>`{!}'));
        $this->assertSame('<p><span class="x">&amp;amp; &lt;s&gt;</span></p>', $this->html('`&amp; <s>`{! .x}'));
    }

    public function testNoInlineConstructRecognizedInside(): void
    {
        $this->assertSame('<p>*not bold*</p>', $this->html('`*not bold*`{!}'));
        $this->assertSame('<p>[t](/u)</p>', $this->html('`[t](/u)`{!}'));
    }

    public function testFlowsInlineWithinParagraph(): void
    {
        $this->assertSame('<p>The word cat is /kaet/ in IPA.</p>', $this->html('The word cat is `/kaet/`{!} in IPA.'));
    }

    public function testParsesToLiteralInlineNodeCarryingVerbatimContent(): void
    {
        $doc = $this->converter->parse('`/kaet/`{! .ipa}');
        $paragraph = $doc->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $node = $paragraph->getChildren()[0];
        $this->assertInstanceOf(LiteralInline::class, $node);
        $this->assertSame('literal_inline', $node->getType());
        $this->assertSame('/kaet/', $node->getContent());
        $this->assertSame('ipa', $node->getAttribute('class'));
    }

    // ==================== Smart typography suppression ====================

    public function testTypographySuppressedInside(): void
    {
        $this->assertSame('<p>a -- b ... "q" (c)</p>', $this->html('`a -- b ... "q" (c)`{!}'));
    }

    public function testTypographyStillTransformsInOrdinaryText(): void
    {
        // Proves the case above is a real suppression, not an inert input.
        $this->assertSame('<p>a – b … “q” ©</p>', $this->html('a -- b ... "q" (c)'));
    }

    public function testTypographySuppressedInsideAttributedLiteral(): void
    {
        $this->assertSame('<p><span class="x">a -- b</span></p>', $this->html('`a -- b`{! .x}'));
    }

    // ==================== Regression guards (unchanged constructs) ====================

    public function testGenericAttributedCodeSpanUnchanged(): void
    {
        $this->assertSame('<p><code class="ipa">x</code></p>', $this->html('`x`{.ipa}'));
    }

    public function testRawInlinePassthroughUnchanged(): void
    {
        $this->assertSame('<p>x</p>', $this->html('`x`{=html}'));
        // ... including its target-routed drop, which the literal never does.
        $this->assertSame('<p></p>', $this->html('`x`{=latex}'));
    }

    public function testSigilNotFirstIsNotLiteral(): void
    {
        // `!` is not a valid attribute identifier, so the strict attribute rule
        // (§14) makes the whole block literal text.
        $this->assertSame('<p><code>x</code>{.ipa !}</p>', $this->html('`x`{.ipa !}'));
    }

    public function testWhitespaceRequiredBetweenSigilAndAttribute(): void
    {
        $this->assertSame('<p><code>x</code>{!.ipa}</p>', $this->html('`x`{!.ipa}'));
    }

    public function testInertOnAnyNodeOtherThanCodeSpan(): void
    {
        $this->assertSame('<p><a href="/u">t</a>{!}</p>', $this->html('[t](/u){!}'));
        $this->assertSame('<p><strong>b</strong>{!}</p>', $this->html('*b*{!}'));
    }

    // ==================== Non-HTML renderers never drop it ====================

    public function testMarkdownEscapesMetacharactersSoTextStaysLiteral(): void
    {
        $this->assertSame('\\*not bold\\*', trim(CarveConverter::markdown()->convert('`*not bold*`{!}')));
    }

    public function testPlainTextEmitsContentAsProse(): void
    {
        $this->assertSame('*not bold*', trim(CarveConverter::plainText()->convert('`*not bold*`{!}')));
    }

    public function testAnsiEmitsContentAsProse(): void
    {
        $this->assertSame('*not bold*', trim(CarveConverter::ansi()->convert('`*not bold*`{!}')));
    }

    public function testTypographyKeptVerbatimInNonHtmlTargets(): void
    {
        $source = '`a -- b ... "q"`{!}';
        $this->assertSame('a -- b ... "q"', trim(CarveConverter::markdown()->convert($source)));
        $this->assertSame('a -- b ... "q"', trim(CarveConverter::plainText()->convert($source)));
        $this->assertSame('a -- b ... "q"', trim(CarveConverter::ansi()->convert($source)));
    }

    public function testAnsiCarriesNoCodeStyling(): void
    {
        // A code span is colorized; the literal is prose, not code.
        $this->assertNotSame('x', trim(CarveConverter::ansi()->convert('`x`')));
        $this->assertSame('x', trim(CarveConverter::ansi()->convert('`x`{!}')));
    }

    // ==================== Heading id / slug contribution ====================

    public function testContributesToHeadingIdSoCrossrefResolves(): void
    {
        // It renders as visible prose, so it must slug like a code span does.
        // Ids are case-preserving; the crossref folds case-insensitively.
        $this->assertSame(
            "<section id=\"Cat\">\n  <h1>Cat</h1>\n  <p>See <a href=\"#Cat\">Cat</a></p>\n</section>",
            $this->html("# `Cat`{!}\n\nSee </#cat>"),
        );
    }

    public function testSlugsExactlyLikeTheEquivalentCodeSpan(): void
    {
        $literal = $this->html("# `Cat`{!}\n\nSee </#cat>");
        $code = $this->html("# `Cat`\n\nSee </#cat>");
        $strip = static fn (string $html): string => (string)preg_replace('#</?code>#', '', $html);
        $this->assertSame($strip($code), $strip($literal));
    }

    public function testCombinesWithSurroundingHeadingText(): void
    {
        $this->assertStringContainsString('id="The-kaet-sound"', $this->html('# The `/kaet/`{!} sound'));
    }

    // ==================== Carve serialization (fmt) ====================

    /**
     * @return array<string, array{string}>
     */
    public static function fmtCases(): array
    {
        return [
            'bare' => ['`/kaet/`{!}'],
            'class' => ['`/kaet/`{! .ipa}'],
            'class-id' => ['`/kaet/`{! .ipa #cat}'],
            'mixed' => ['`x`{! .a #b k=v}'],
            'escaped-content' => ['`a<b>`{!}'],
            'no-typography' => ['`*not bold*`{!}'],
            'verbatim-typography' => ['`a -- b ... "q" (c)`{!}'],
        ];
    }

    #[DataProvider('fmtCases')]
    public function testFmtRoundTripsSourceSpelling(string $source): void
    {
        $this->assertSame($source, trim(CarveConverter::toCarve($source)));
    }

    public function testFmtWidensBacktickFenceWhenContentContainsBackticks(): void
    {
        $this->assertSame('``a`b``{!}', trim(CarveConverter::toCarve('``a`b``{!}')));
        $this->assertSame('```a``b```{!}', trim(CarveConverter::toCarve('```a``b```{!}')));
        // Content that starts/ends with a backtick gets the padding spaces back.
        $this->assertSame('`` `x` ``{!}', trim(CarveConverter::toCarve('`` `x` ``{!}')));
    }

    public function testFmtIsIdempotent(): void
    {
        $cases = [
            '`/kaet/`{!}',
            '`/kaet/`{! .ipa #cat}',
            '`x`{! .a #b k=v}',
            '``a`b``{!}',
            'The word cat is `/kaet/`{!} in IPA',
        ];
        foreach ($cases as $source) {
            $once = CarveConverter::toCarve($source);
            $this->assertSame($once, CarveConverter::toCarve($once));
        }
    }

    public function testFmtPreservesToHtmlInvariant(): void
    {
        $cases = [
            '`/kaet/`{!}',
            '`/kaet/`{! .ipa #cat}',
            '`x`{! .a #b k=v}',
            '`a<b>`{!}',
            '`*not bold*`{!}',
            '`a -- b ... "q" (c)`{!}',
            '``a`b``{!}',
            'The word cat is `/kaet/`{!} in IPA',
            // The unchanged neighbours must keep the invariant too.
            '`x`{.ipa}',
            '`x`{.ipa !}',
            '[t](/u){!}',
        ];
        foreach ($cases as $source) {
            $this->assertSame(
                $this->html($source),
                $this->html(CarveConverter::toCarve($source)),
                'invariant failed for: ' . $source,
            );
        }
    }

    // ==================== Profiles ====================

    public function testClassifiedAsCodeProfileType(): void
    {
        // An inline literal is a code span with the wrapper dropped, so it is
        // allowed exactly where `code` is and denied where `code` is.
        $profile = Profile::full();
        $this->assertSame(
            $profile->isTypeAllowed(NodeType::CODE),
            $profile->isTypeAllowed(NodeType::LITERAL_INLINE),
        );
    }

    public function testAllowedWhereverCodeIsAllowedInEveryPreset(): void
    {
        foreach ([Profile::comment(), Profile::minimal(), Profile::article(), Profile::full()] as $profile) {
            $converter = new CarveConverter(profile: $profile);
            // Its attributes render exactly as an attributed code span's would.
            $this->assertSame('<p><span class="ipa">x</span></p>', trim($converter->convert('`x`{! .ipa}')));
            $this->assertSame('<p>x</p>', trim($converter->convert('`x`{!}')));
            // Parity: the attributed code span it is a variant of is likewise allowed.
            $this->assertSame('<p><code class="ipa">x</code></p>', trim($converter->convert('`x`{.ipa}')));
        }
    }

    public function testDeniedWhereCodeIsDenied(): void
    {
        $profile = Profile::full();
        $profile->denyInline([NodeType::CODE]);
        $converter = new CarveConverter(profile: $profile);
        // Denying code demotes the literal to plain text just as it does a code span.
        $this->assertSame('<p>x</p>', trim($converter->convert('`x`')));
        $this->assertSame('<p>x</p>', trim($converter->convert('`x`{!}')));
        $this->assertSame('<p>hi</p>', trim($converter->convert('`hi`{! .ipa}')));
    }
}
