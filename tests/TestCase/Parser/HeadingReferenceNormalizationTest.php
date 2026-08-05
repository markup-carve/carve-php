<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An implicit heading reference matches the heading index NFC-normalized, and
 * NOT NFKC (PART 9R R1, carve#725).
 *
 * Heading IDS have been NFC-normalized since section 25 while this key was not,
 * so a document published `id="Cafe<U+0301> as NFC"` and then declined the
 * precomposed reference against the very heading that produced it - the same
 * alphabet on one side of the resolution and not the other. Both spellings
 * render identically, so the miss had no visible cause; that is what made it
 * survive.
 *
 * NFC is also a WEAKER fold than the case fold R1 already admits: case folding
 * relates codepoints Unicode calls distinct, NFC relates sequences Unicode
 * DEFINES as the same.
 *
 * The NFKC cases are the other half of the claim, not decoration: a fix reaching
 * for FORM_KC - or for the ASCII transliteration this engine uses for ids -
 * would resolve them and change WHICH text the author is quoting.
 *
 * This engine folds in TWO places - BlockParser::foldReferenceLabel and
 * HeadingReferenceCollector::foldLabel - and both normalize, because two copies
 * of a matching rule drift.
 *
 * Only the COLLAPSED form `[text][]` is asserted here. Whether an EXPLICIT
 * `[text][label]` reaches the heading index at all is a separate and unsettled
 * question: this engine does not fold that path (not even case, so
 * `[q][getting started]` misses `# Getting Started`), while the oracle and
 * carve-js do. R1's wording says the fallback is for `[text][]`, which sides
 * with this engine, so it is filed rather than changed here (carve#742).
 */
class HeadingReferenceNormalizationTest extends TestCase
{
    /**
     * `e` + U+0301 COMBINING ACUTE.
     *
     * @var string
     */
    protected const DECOMPOSED = "Cafe\u{0301}";

    /**
     * Precomposed U+00E9.
     *
     * @var string
     */
    protected const PRECOMPOSED = "Caf\u{00E9}";

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testResolvesAPrecomposedReferenceAgainstADecomposedHeading(): void
    {
        $html = $this->html('# ' . self::DECOMPOSED . "\n\nsee [" . self::PRECOMPOSED . "][]\n");

        $this->assertStringContainsString('<a href="#' . self::PRECOMPOSED . '">', $html);
        // The id side was already NFC; this is the assertion that the lookup now
        // uses the same alphabet.
        $this->assertStringContainsString('id="' . self::PRECOMPOSED . '"', $html);
    }

    public function testResolvesADecomposedReferenceAgainstAPrecomposedHeading(): void
    {
        $html = $this->html('# ' . self::PRECOMPOSED . "\n\nsee [" . self::DECOMPOSED . "][]\n");

        $this->assertStringContainsString('<a href="#' . self::PRECOMPOSED . '">', $html);
    }

    public function testLeavesTheHeadingTextAsTheAuthorSpelledIt(): void
    {
        // Normalization is for MATCHING. The rendered heading keeps its own
        // bytes - folding the visible text would be a different change.
        $html = $this->html('# ' . self::DECOMPOSED . "\n\nsee [" . self::PRECOMPOSED . "][]\n");

        $this->assertStringContainsString('<h1>' . self::DECOMPOSED . '</h1>', $html);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function sameSpellingProvider(): array
    {
        return [
            'decomposed' => [self::DECOMPOSED],
            'precomposed' => [self::PRECOMPOSED],
        ];
    }

    /**
     * The rows that were already unanimous across the implementations. Kept so a
     * fix cannot trade them away for the cross-spelling ones.
     */
    #[DataProvider('sameSpellingProvider')]
    public function testStillResolvesTheSameSpellingCases(string $spelling): void
    {
        $html = $this->html('# ' . $spelling . "\n\nsee [" . $spelling . "][]\n");

        $this->assertStringContainsString('<a href="#' . self::PRECOMPOSED . '">', $html);
    }

    public function testStillFoldsCaseAndCollapsesWhitespace(): void
    {
        $html = $this->html("# Getting  Started\n\nsee [getting started][]\n");

        $this->assertStringContainsString('<a href="#Getting-Started">', $html);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function compatibilityProvider(): array
    {
        return [
            // U+FB01 LATIN SMALL LIGATURE FI.
            'ligature' => ["\u{FB01}le", 'file'],
            // U+2460 CIRCLED DIGIT ONE.
            'circled digit' => ["\u{2460} one", '1 one'],
            // U+FF41 U+FF42 FULLWIDTH LATIN SMALL LETTERS A, B.
            'fullwidth' => ["\u{FF41}\u{FF42}", 'ab'],
        ];
    }

    /**
     * NFKC would resolve each of these. It stays out: compatibility folding
     * changes which text the author is quoting, not how it is spelled.
     */
    #[DataProvider('compatibilityProvider')]
    public function testDoesNotFoldCompatibilityEquivalence(string $heading, string $reference): void
    {
        $html = $this->html('# ' . $heading . "\n\nsee [" . $reference . "][]\n");

        $this->assertStringContainsString('[' . $reference . '][]', $html);
    }
}
