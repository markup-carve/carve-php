<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function array_is_list;
use function array_key_last;
use function array_keys;
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function glob;
use function is_array;
use function json_encode;

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
 *  - THE TREE: parse(fmt(src)) == parse(src), PART 11 §1's own first
 *    invariant, which none of the others can see. §1 says in as many words
 *    that `to_html(fmt(x)) == to_html(x)` holding is NECESSARY, NOT
 *    SUFFICIENT: two spellings that render alike are still two spellings, and
 *    §2a lists the measured cases where a writer turned one construct into
 *    another that renders the same - `* %%` written as `* +`, a line comment
 *    becoming the continuation marker. Every one of those passes the three
 *    properties above (carve-php#1523).
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
     * The PUBLISHED tree, canonicalized MODULO ESCAPING.
     *
     * Three things are forgiven, and only three:
     *
     *  - `srcByteLength` and `pos` count the SOURCE. The formatted bytes differ
     *    from the authored ones by design, so comparing spans would fail on
     *    every reflowed document and say nothing about the tree.
     *  - an `escaped_text` node IS text. §1's equality has to be modulo
     *    escaping or it contradicts §2: an escape is REQUIRED wherever omitting
     *    it would change the re-parse, so a document whose canonical form needs
     *    one necessarily gains a node its source did not have. Measured on this
     *    corpus, strict equality is red on 58 documents and the MINIMAL escape
     *    pass is red on 50 of them too - there is no strategy that satisfies
     *    it, which is what makes it the wrong question rather than 58 bugs.
     *  - adjacent text nodes are ONE run. Where an escape lands splits a run in
     *    two, and run segmentation is not a fact about the document. This is
     *    what `CarveRenderer::canonicalizeAst()` does for the same reason.
     *
     * Nothing else is forgiven: a node appearing or vanishing, a construct
     * becoming a different construct, an attribute or a text value moving all
     * fail. That is the whole point - it is §2a's family this catches.
     *
     * `canonicalizeAst()` itself is deliberately NOT reused: it walks
     * `ReflectionObject` properties, so it compares internal parser fields and
     * reports 18 differences the published AST does not have.
     *
     * @return mixed
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $child) {
                $child = self::canonical($child);
                $last = $out === [] ? null : array_key_last($out);
                if (
                    $last !== null
                    && is_array($child)
                    && is_array($out[$last])
                    && array_keys($child) === ['type', 'value']
                    && array_keys($out[$last]) === ['type', 'value']
                    && $child['type'] === 'text'
                    && $out[$last]['type'] === 'text'
                ) {
                    $out[$last]['value'] .= $child['value'];

                    continue;
                }
                $out[] = $child;
            }

            return array_values($out);
        }

        unset($value['srcByteLength'], $value['pos']);
        if (($value['type'] ?? null) === 'escaped_text') {
            $value['type'] = 'text';
        }
        // NOT INTO `attrs`. AstCodec::mapInternalTypes() states the same rule
        // for the same reason: `attrs` holds named slots rather than nodes, and
        // a `keyValues` entry can be spelled `type`, `pos` or `srcByteLength` -
        // descending would rename or delete an ATTRIBUTE. Attributes are
        // content, so they compare verbatim, and skipping the one map whose keys
        // an author controls is what makes the node handling above sound
        // everywhere else.
        foreach ($value as $key => $child) {
            if ($key === 'attrs') {
                continue;
            }
            $value[$key] = self::canonical($child);
        }

        return $value;
    }

    /**
     * The canonical published tree of a document, as a comparable string.
     */
    private static function tree(string $source): string
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));

        return (string)json_encode(self::canonical($encoded));
    }

    /**
     * parse(fmt(src)) == parse(src) - PART 11 §1's first invariant.
     *
     * Green over the whole pinned corpus, so there is no allowlist. An entry
     * here would silence the comparison whether or not the engine passed it,
     * and nothing needs silencing.
     */
    #[DataProvider('corpusProvider')]
    public function testTheFormattedDocumentParsesToTheSameTree(string $slug, string $crv): void
    {
        $this->assertSame(
            self::tree($crv),
            self::tree(CarveConverter::toCarve($crv)),
            'parse(fmt(x)) != parse(x) for ' . $slug,
        );
    }

    /**
     * THE COMPARISON CAN FAIL, and fails on what the other three miss.
     *
     * §2a's own shape: two spellings that render identically and are not the
     * same document. A thematic break written `***` and one written `---` both
     * render `<hr />`, are each idempotent under the writer, and each re-parse
     * cleanly - all three existing properties pass either way - and the marker
     * is part of the tree.
     *
     * That pair is not academic here. The writer respells EVERY break in a
     * document as `***` when the finished bytes would otherwise be misread as
     * frontmatter, so substituting one construct for another is a move this
     * writer actually makes, and until now nothing in this file could see it.
     */
    public function testTheTreeComparisonCatchesASubstitutedConstruct(): void
    {
        $source = "a\n\n---\n";
        $substituted = "a\n\n***\n";
        $converter = new CarveConverter();

        // The three properties this file already had, on the substitution:
        // identical render, idempotent, re-parses. All satisfied.
        $this->assertSame($converter->convert($source), $converter->convert($substituted));
        $this->assertSame($substituted, CarveConverter::toCarve($substituted));
        $converter->parse($substituted);

        // And it is not the same document.
        $this->assertNotSame(self::tree($source), self::tree($substituted));
    }

    /**
     * The canonicalization forgives escaping and nothing more.
     *
     * Without this, a normalizer that flattened too much would make the sweep
     * above pass by seeing nothing - the dead-check shape carve-php#1523 exists
     * to close. So both halves are stated: an invented escape IS forgiven (it
     * has to be, or §1 contradicts §2), and a substituted construct is not.
     */
    public function testTheCanonicalizationForgivesEscapingAndNothingElse(): void
    {
        // Escaping: forgiven. `{^x` and `{\^x` render alike and differ only in
        // where a text run is split.
        $this->assertSame(self::tree("{^x\n"), self::tree("{\\^x\n"));

        // Text content: not forgiven.
        $this->assertNotSame(self::tree("a b\n"), self::tree("a c\n"));

        // A node vanishing: not forgiven.
        $this->assertNotSame(self::tree("a /b/ c\n"), self::tree("a b c\n"));

        // An attribute moving: not forgiven.
        $this->assertNotSame(self::tree("{#x}\na\n"), self::tree("{#y}\na\n"));

        // An attribute NAMED like node metadata is CONTENT, not metadata. The
        // one map whose keys an author controls is never descended into, so a
        // `type`, `pos` or `srcByteLength` attribute is compared as written.
        $this->assertNotSame(
            self::tree("{pos=\"x\"}\na\n"),
            self::tree("{pos=\"y\"}\na\n"),
        );
        $this->assertNotSame(
            self::tree("{srcByteLength=\"1\"}\na\n"),
            self::tree("{srcByteLength=\"2\"}\na\n"),
        );
        $this->assertNotSame(
            self::tree("{type=\"x\" pos=\"y\"}\na\n"),
            self::tree("{type=\"x\" pos=\"z\"}\na\n"),
        );
        $this->assertNotSame(
            self::tree("{type=\"escaped_text\"}\na\n"),
            self::tree("{type=\"text\"}\na\n"),
        );
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
