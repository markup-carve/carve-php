<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function glob;

/**
 * Corpus-level safety net for the AST->Carve formatter (`carve fmt`).
 *
 * Mirrors the carve-js invariant test (test/render-carve.test.ts): it runs the
 * formatter over every spec corpus `.crv` file and asserts the two properties a
 * source-faithful formatter must hold:
 *
 *  - Semantic preservation: convert(fmt(src)) === convert(src). Formatting must
 *    not change the rendered HTML at all (exact bytes, no normalization).
 *  - Idempotency: fmt(fmt(src)) === fmt(src). A second pass is a no-op.
 *
 * Plus a clean-parse guard: the formatted output must re-parse without error.
 *
 * Unlike the unit-style CarveFormatterTest (which normalizes HTML before
 * comparing), this test compares the rendered HTML byte-for-byte, matching the
 * strict `.toBe()` semantics of the carve-js reference. All 380 corpus cases
 * satisfy both invariants under this strict comparison, so there is no exclusion
 * list. If a future formatter change breaks a case, this test fails loudly
 * rather than passing CI on a regression.
 */
#[Group('corpus')]
class CarveFmtCorpusTest extends TestCase
{
    /**
     * @throws \RuntimeException when the spec submodule is not initialized
     *
     * @return array<string, array{slug: string, crv: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = dirname(__DIR__) . '/spec/tests/corpus';
        $crvFiles = glob($dir . '/*.crv') ?: [];
        if ($crvFiles === []) {
            throw new RuntimeException(
                'Carve spec corpus not found at ' . $dir . '. Did you initialize the submodule? '
                . 'Run: git submodule update --init tests/spec',
            );
        }

        $cases = [];
        foreach ($crvFiles as $crvPath) {
            $slug = basename($crvPath, '.crv');
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
            ];
        }

        return $cases;
    }

    /**
     * The documents whose canonical form the spec pins, as `<slug>.fmt`.
     *
     * @return array<string, array{slug: string, crv: string, fmt: string}>
     */
    public static function pinnedProvider(): array
    {
        $dir = dirname(__DIR__) . '/spec/tests/corpus';
        $cases = [];
        foreach (glob($dir . '/*.fmt') ?: [] as $fmtPath) {
            $slug = basename($fmtPath, '.fmt');
            $crvPath = $dir . '/' . $slug . '.crv';
            if (!file_exists($crvPath)) {
                continue;
            }
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
                'fmt' => (string)file_get_contents($fmtPath),
            ];
        }

        return $cases;
    }

    /**
     * A `.fmt` fixture is read, so the sweep below can fail.
     *
     * Guards against a glob that quietly matches nothing - which is the state
     * the fixtures were already in for their first five releases
     * (markup-carve/carve#671).
     */
    public function testAPinnedFixtureIsRead(): void
    {
        $this->assertGreaterThanOrEqual(5, count(self::pinnedProvider()), 'no .fmt fixtures were found');
    }

    /**
     * fmt(src) matches the canonical form the spec pins (PART 11 §2).
     *
     * The two invariants above cannot see this. Both hold for every writer
     * divergence found so far: a comment renders nothing, so a body written at
     * the wrong column still preserves the HTML, and a writer is happily
     * idempotent about a spelling it picked itself. The BYTES are the only
     * thing that separates one canonical form from two, and PART 11 §2 is
     * normative about which one it is.
     *
     * The spec repo reads these fixtures against its pinned carve-js build.
     * That leaves this engine's own writer unchecked against them, which is the
     * half of markup-carve/carve#671 its own test file names as still open.
     *
     * Measured before adding: this engine already matches all of them, so this
     * lands green and bites only on a regression.
     */
    #[DataProvider('pinnedProvider')]
    public function testFormatMatchesThePinnedCanonicalForm(string $slug, string $crv, string $fmt): void
    {
        $this->assertSame($fmt, CarveConverter::toCarve($crv), 'the writer disagrees with the pinned canonical form for ' . $slug);
    }

    /**
     * convert(fmt(src)) === convert(src), compared byte-for-byte.
     */
    #[DataProvider('corpusProvider')]
    public function testSemanticPreservation(string $slug, string $crv): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($crv);

        $this->assertSame(
            $converter->convert($crv),
            $converter->convert($formatted),
            'Formatting changed the rendered HTML for ' . $slug,
        );
    }

    /**
     * fmt(fmt(src)) === fmt(src).
     */
    #[DataProvider('corpusProvider')]
    public function testIdempotency(string $slug, string $crv): void
    {
        $formatted = CarveConverter::toCarve($crv);

        $this->assertSame(
            $formatted,
            CarveConverter::toCarve($formatted),
            'Formatter is not idempotent for ' . $slug,
        );
    }

    /**
     * The formatted output must re-parse without throwing.
     */
    #[DataProvider('corpusProvider')]
    public function testFormattedOutputParsesCleanly(string $slug, string $crv): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($crv);

        $converter->parse($formatted);
        $converter->parse(CarveConverter::toCarve($formatted));

        $this->addToAssertionCount(1);
    }

    /**
     * A verbatim span whose content is entirely spaces must be neither stripped
     * by the parser nor padded by the serializer. Padding it grew the span by
     * two spaces on every fmt pass, breaking both formatter guarantees. Covers
     * the code span, inline literal and math paths, which share one strip
     * helper.
     *
     * @return array<int, array{0: string}>
     */
    public static function allSpaceVerbatimProvider(): array
    {
        return [
            ['` `'], ['`  `'], ['`   `'],
            ['!` `'], ['!`  `'], ['!`   `'],
            ['$` x `'], ['$`  `'],
            ['``  ``'], ['!``  ``'],
            ['`a b`'], ['` a `'],
        ];
    }

    #[DataProvider('allSpaceVerbatimProvider')]
    public function testAllSpaceVerbatimContentRoundTrips(string $src): void
    {
        $converter = new CarveConverter();
        $formatted = rtrim(CarveConverter::toCarve($src));

        $this->assertSame(
            $formatted,
            rtrim(CarveConverter::toCarve($formatted)),
            'Formatter is not idempotent for ' . var_export($src, true),
        );
        $this->assertSame(
            $converter->convert($src),
            $converter->convert($formatted),
            'toHtml(fmt(x)) !== toHtml(x) for ' . var_export($src, true),
        );
    }

    /**
     * The all-space guard matches the executable spec's codeText() and the
     * CommonMark rule ("...but does not consist entirely of space characters").
     */
    public function testAllSpaceVerbatimContentIsPreservedNotCollapsed(): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString('<code>  </code>', $converter->convert('`  `'));
        // A non-all-space span still strips exactly one space per side.
        $this->assertStringContainsString('<code>a</code>', $converter->convert('` a `'));
        // Math takes the same strip as a code span (carve-js / carve-rs parity).
        $this->assertStringContainsString('\(x\)', $converter->convert('$` x `'));
    }
}
