<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
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
use function in_array;
use function is_array;
use function json_encode;
use function max;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

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
 *  - MINIMALITY, PART 11 §2's other half: an escape is written IF AND ONLY IF
 *    omitting it would change the re-parse. The tree comparison cannot see
 *    this one, because it has to forgive escaping or §1 contradicts §2 - so an
 *    invented escape passes every property above. It is red on 28 documents
 *    and gated by a shrink-only ratchet (carve-php#1533).
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
    /** Canonical writer rulings implemented ahead of this repository's spec pin. */
    private const AHEAD_OF_PIN = [
        '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace',
        '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2',
        '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-3',
        '407-one-consumed-boolean-spells-the-looseness-no-blank-line-can-2',
    ];

    /**
     * The two causes measured here, one of which every ratchet entry below
     * must name. An entry belonging to none of them is a cause nobody has
     * looked at yet, which is a finding rather than a resident.
     *
     * TWO HAVE BEEN RETIRED BY WORK. `escalation: ` went when PART 11 §2b
     * narrowed the fallback from the document to the failing unit, and
     * `unit scope: ` went when §2's test was taken per opener occurrence inside
     * that unit (markup-carve/carve#1533). What is left is the two causes that
     * are not scope questions at all.
     *
     * @var array<int, string>
     */
    private const IDLE_ESCAPE_CAUSES = ['opener run: ', 'minimal class: '];

    /**
     * THE DEBT, NOT A BLESSING: documents where the writer emits an escape the
     * re-parse does not need, with the exact count of invented escapes.
     *
     * PART 11 §2 escapes a character IF AND ONLY IF omitting the escape would
     * change the re-parse, and this list is where this engine breaks the "only
     * if" half. It is a shrink-only ratchet: an entry may be lowered or deleted
     * as the writer improves, and NOTHING may be added or raised. A count that
     * goes up is a regression and fails; a count that goes down fails too, so
     * the entry is tightened rather than left as slack a later defect could
     * spend.
     *
     * Every entry carries a reason, because an entry nobody can explain is the
     * next thing to investigate. An empty reason fails
     * {@see self::testEveryRatchetEntryCarriesACountAndAReason()}.
     *
     * Seeded from measurement at d3ae737 at 28 documents and 72 invented
     * escapes; re-measured at 24 and 57 when PART 11 §2b narrowed the
     * escalation, and carried to 25 and 59 by the pin that brought §2b's own
     * corpus document with it. §2's per-OPENER-OCCURRENCE test then retired the
     * 47 that were one unit written conservatively in full, and this is what is
     * left: 12 escapes across 5 documents (markup-carve/carve#1533). carve-js
     * measures the same 5 with the same counts, character for character, and
     * the two writers agree byte for byte on every corpus document.
     *
     * `unit scope` WAS THE 20-DOCUMENT CAUSE AND IS GONE. PART 11 §4's
     * two-render strategy has one knob per unit - minimal or conservative - so
     * a unit that failed was written conservatively IN FULL, and every other
     * candidate character in it was escaped with the one that needed it. §2
     * takes the decision per OPENER OCCURRENCE, and the writer now runs the
     * same halving search one level finer: `\{.note}` where the unit-scoped form
     * wrote `\{\.note\}`.
     *
     * OPENER RUN, three documents since the pin moved past carve#1516: §2's
     * THE UNIT IS THE OPENER requires the WHOLE
     * opener run escaped - `\#\# H` and not `\## H`, `\*\*\*` and not `\***` -
     * and PART 11 §2b names the first of those as its own worked example. The
     * sweep removes ONE backslash at a time, so it reads the second `\#` as
     * idle: with the first still there no heading forms either way. These
     * entries are a floor this measurement cannot go below while §2 says what
     * it says, and they are here to be seen rather than to be fixed. The
     * occurrence search is why they are a floor rather than an accident: it
     * offers a RUN back whole, so the half-escaped run §2 forbids is not a
     * state it can reach.
     *
     * The third opener-run document arrived WITH THE CORPUS rather than with
     * the writer. `396-an-idle-escape-does-not-spread-from-the-block-that-
     * needed-one` is the document carve#1516 added - an indented `## H` plus a
     * second paragraph - and this writer emits the spec's own `.fmt` golden for
     * it byte for byte, as carve-js and carve-rs do, each measuring the same 2
     * on it. PART 11 §2b's prose still says two opener-run documents and 24 /
     * 57 overall; that count was taken on a pin that predated its own corpus
     * case.
     *
     * MINIMAL CLASS, the other two: both passes agree, so nothing escalated,
     * and the escape is still idle.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const IDLE_ESCAPE_RATCHET = [
        '72-escape-coverage-2' => [4, 'minimal class: a literal backslash is written doubled, and a lone backslash before a non-escapable character re-parses the same bare'],
        '103-heading-marker-column-zero-2' => [2, 'opener run: the heading opener `##` is escaped in full, and removing either backslash alone still leaves a paragraph'],
        '132-thematic-break-requires-contiguous-markers-3' => [3, 'opener run: the break opener `***` is escaped in full, and removing any one backslash alone still leaves a paragraph'],
        '390-a-table-cell-s-marker-run-ends-at-a-space-5' => [1, 'minimal class: an authored `\=` is kept after the writer\'s own cell padding retired it - padded, the `=` no longer starts the cell'],
        '396-an-idle-escape-does-not-spread-from-the-block-that-needed-one' => [2, 'opener run: the heading opener `##` is escaped in full, and removing either backslash alone still leaves a paragraph'],
    ];

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
        if (in_array($slug, self::AHEAD_OF_PIN, true)) {
            $this->assertNotSame($fmt, CarveConverter::toCarve($crv), 'the ahead-of-pin declaration is stale for ' . $slug);

            return;
        }
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
     * The canonical form the spec pins for a document, or null where it pins none.
     */
    private static function pinnedCanonicalForm(string $slug): ?string
    {
        $path = dirname(__DIR__) . '/spec/tests/corpus/' . $slug . '.fmt';

        return file_exists($path) ? (string)file_get_contents($path) : null;
    }

    /**
     * True where a node is a bare WRAPPER: one child and nothing of its own.
     *
     * `type` and `children` and no third key, so the node carries no attributes,
     * no label and no value that dissolving it would take with it. That is
     * exactly the bound PART 11 §1c states for the loss it permits - "the
     * content, its attributes and its neighbours all survive as themselves".
     *
     * @param array<string, mixed> $node
     */
    private static function isBareWrapper(array $node): bool
    {
        $keys = array_keys($node);
        sort($keys);
        if ($keys !== ['children', 'type']) {
            return false;
        }

        return is_array($node['children']) && count($node['children']) === 1 && is_array($node['children'][0]);
    }

    /**
     * The tree with every bare single-child wrapper dissolved into its child.
     *
     * EVERY list-valued slot is walked, not just `children`. A block does not
     * always reach its children through that key: a list holds `items`, a table
     * holds `rows` and `cells`, and `caption`, `inline`, `content`, `title`,
     * `columns` and `bodies` each carry nodes somewhere in the corpus. Walking
     * `children` alone made this blind to a wrapper loss anywhere inside a list
     * or a table - so a canonical form that dissolved a paragraph in a list item
     * compared unequal here and was rejected as "more than a wrapper loss" when
     * it was exactly a wrapper loss (corpus 411-5).
     *
     * Keyed on the SHAPE of the value rather than on a list of key names, so a
     * new child-bearing slot is covered the day it appears instead of the day
     * someone remembers to add it here.
     *
     * Not applied to the root, which has no parent to dissolve it into. Attrs
     * are not descended into, for the reason `canonical()` gives above - they
     * are an associative map rather than a list, so `array_is_list()` skips
     * them, which is also what keeps this from walking scalar-keyed data.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function withoutBareWrappers(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }

            $out = [];
            foreach ($value as $child) {
                if (!is_array($child)) {
                    $out[] = $child;

                    continue;
                }
                $child = self::withoutBareWrappers($child);
                $out[] = self::isBareWrapper($child) ? $child['children'][0] : $child;
            }
            $node[$key] = $out;
        }

        return $node;
    }

    /**
     * A document's tree with the wrappers PART 11 §1c may dissolve taken out.
     *
     * Two documents that agree here differ by NOTHING BUT wrapper loss. That is
     * what qualifies a pinned canonical form as a §1c ceiling rather than a
     * disagreement: dropped content, a reordering, a changed attribute or a
     * changed node type all survive this normalization and still compare
     * unequal.
     */
    private static function shapeWithoutWrappers(string $source): string
    {
        /** @var array<string, mixed> $encoded */
        $encoded = self::canonical((new AstCodec())->encode((new CarveConverter())->parse($source)));

        return (string)json_encode(self::withoutBareWrappers($encoded));
    }

    /**
     * THE §1c WRAPPER BOUND IS NARROW, pinned shape by shape.
     *
     * The corpus sweep below cannot show this, and that is the whole reason
     * this test exists. `withoutBareWrappers()` is applied to BOTH sides of the
     * comparison, so WIDENING it can only ever hide a difference, never create
     * one: whatever extra shape it swallows, it swallows identically in the
     * source tree and in the pinned canonical form, and the two still agree.
     *
     * MEASURED, not assumed. `isBareWrapper()` was mutated to ignore every key
     * beyond `children` - so a node carrying attributes, a label or an href
     * would dissolve and lose them - and the full 7111-test sweep stayed GREEN.
     * That mutation is not a no-op: walking the pinned corpus with both
     * predicates, they disagree on 1107 nodes across the 1404 documents, among
     * them a `heading` with `attrs`, a `link` with an `href` and a `footnote`
     * with a `label`. So the sweep says nothing whatsoever about how wide this
     * bound is, and the near misses have to be asserted directly.
     *
     * Each shape below is one PART 11 §1c does not reach. The clause permits
     * losing a WRAPPER - a node with one child and nothing of its own - because
     * "the content, its attributes and its neighbours all survive as
     * themselves". A node with a second key of its own has something to lose,
     * so it is not a wrapper and its loss is a disagreement, not a ceiling.
     *
     * carve-rs states the same bound as a predicate over what the shape spells
     * and pins its width the same way (markup-carve/carve-rs#1353). Both
     * sourcings are conforming; what has to hold in either is the bound, which
     * is what this pins.
     */
    public function testThePartElevenOneCWrapperBoundReachesBareWrappersAndNoOthers(): void
    {
        $image = ['type' => 'image', 'src' => 'a.jpg', 'alt' => 'Apollo'];
        $dissolves = static function (array $candidate) use ($image): bool {
            $out = self::withoutBareWrappers(['type' => 'document', 'children' => [$candidate]]);

            return $out['children'][0] === $image;
        };

        // THE SHAPE THE CLAUSE REACHES: one child, and nothing of its own.
        $this->assertTrue(
            $dissolves(['type' => 'paragraph', 'children' => [$image]]),
            'a bare single-child wrapper is the one loss PART 11 §1c permits',
        );

        // ATTRIBUTES ARE CONTENT. Dissolving this wrapper would take them with
        // it, which is the opposite of "its attributes survive as themselves".
        $this->assertFalse(
            $dissolves(['type' => 'paragraph', 'attrs' => ['classes' => ['k']], 'children' => [$image]]),
            'a wrapper carrying attributes is not bare: dissolving it would drop them',
        );

        // A LABEL, AN HREF, A LEVEL, A KIND, A HEADER FLAG - every one of these
        // is a key the node owns, and every one of them is a real corpus shape
        // the widened predicate swallowed.
        foreach (
            [
                'footnote' => ['type' => 'footnote', 'label' => '1', 'children' => [$image]],
                'link' => ['type' => 'link', 'href' => 'u', 'children' => [$image]],
                'heading' => ['type' => 'heading', 'level' => 1, 'children' => [$image]],
                'admonition' => ['type' => 'admonition', 'kind' => 'note', 'children' => [$image]],
                'table_cell' => ['type' => 'table_cell', 'header' => true, 'children' => [$image]],
            ] as $kind => $node
        ) {
            $this->assertFalse(
                $dissolves($node),
                'a ' . $kind . ' owns a key beyond its child, so it is not a wrapper §1c may dissolve',
            );
        }

        // A SECOND NODE BESIDE IT. The clause is about a block whose WHOLE
        // content is a single node.
        $this->assertFalse(
            $dissolves(['type' => 'paragraph', 'children' => [$image, ['type' => 'text', 'value' => 'x']]]),
            'a wrapper holding a neighbour beside its child is not a lone-content block',
        );

        // NO CHILDREN AT ALL, which has no child to dissolve into.
        $this->assertFalse(
            $dissolves(['type' => 'paragraph', 'children' => []]),
            'an empty block dissolves into nothing',
        );

        // THE ROOT IS NEVER DISSOLVED - it has no parent to dissolve into, and
        // `withoutBareWrappers()` only ever rewrites a node's children.
        $root = ['type' => 'document', 'children' => [$image]];
        $this->assertSame($root, self::withoutBareWrappers($root), 'the root keeps its wrapper');
    }

    /**
     * parse(fmt(src)) == parse(src) - PART 11 §1's first invariant.
     *
     * There is no allowlist, and the one exemption below is NOT one: it is the
     * spec's own ceiling, and it is DERIVED from the corpus rather than listed,
     * so it cannot go stale by being forgotten.
     *
     * WHICH RULE THIS ENGINE FOLLOWS, so the next reader need not find the
     * ticket. markup-carve/carve#1679 ruled on the shape of this exemption
     * across the three engines. What is canonical is THE BOUND: only the
     * dissolution of a bare single-child wrapper is forgiven, and every other
     * difference between the two trees still fails the invariant. Where the
     * exemption is SOURCED from is secondary and both sourcings conform - carve-rs
     * states a predicate over what the shape spells, this engine derives the set
     * from the corpus and applies the bound explicitly below. So do not
     * "converge" this on carve-rs's spelling: the bound is the rule, and
     * `testThePartElevenOneCWrapperBoundReachesBareWrappersAndNoOthers()` is
     * where its width is pinned.
     *
     * PART 11 §1c (markup-carve/carve#1658) states where the invariant is
     * UNATTAINABLE rather than unmet: where a block's whole content is a single
     * node whose own spelling at the block's column reads back as a block opener
     * of that node's kind, the writer emits that spelling and the wrapper is
     * LOST. Corpus 411 is that shape - a lone image indented by one space, which
     * this engine reads as a paragraph since carve-php#1683, whose canonical form
     * (markup-carve/carve#1673) dedents it to column 0, where the same image is
     * the standalone block image. §1c is explicit that the ceiling is UNIFORM AND
     * NOT POSITIONAL: the indented spelling exists at top level and the writer
     * still does not use it. So the sidecar and the invariant cannot both hold,
     * and §1c says the sidecar is the one that does.
     *
     * {@see \MarkupCarve\Carve\Renderer\CarveRenderer} carries the other half
     * of what §1c asks: a producer with no diagnostic channel STATES the ceiling
     * in its contract, and that carve-out list already names this shape.
     *
     * THE EXEMPTION STILL ASSERTS, which is what separates it from a silencing.
     * The formatted tree must equal the SIDECAR's tree EXACTLY, so every writer
     * regression the invariant would have caught is caught here instead - and
     * that includes the one worth naming: a writer that started preserving the
     * wrapper would emit the source's tree, which is not the sidecar's, so this
     * assertion goes red and the exemption has to be re-argued rather than
     * quietly covering it.
     *
     * A SECOND `assertNotSame` AGAINST THE SOURCE TREE WOULD BE DEAD, and it is
     * deliberately not written. Reaching it requires the first assertion to have
     * passed, so `$written === tree($canonical)`, and the branch is only entered
     * when `tree($canonical) !== tree($crv)` - so `$written !== tree($crv)`
     * holds by construction and no input can fail it. carve#755 catalogs eleven
     * checks that could not detect what they claimed; this would have been the
     * twelfth. What that assertion was reaching for is real, and it is a
     * question about the CORPUS rather than about one document, so it is asked
     * once where it can actually fail: see
     * `testThePartElevenOneCCeilingIsReached()` below.
     */
    #[DataProvider('corpusProvider')]
    public function testTheFormattedDocumentParsesToTheSameTree(string $slug, string $crv): void
    {
        $canonical = self::pinnedCanonicalForm($slug);
        $written = self::tree(CarveConverter::toCarve($crv));

        if ($canonical !== null && self::tree($canonical) !== self::tree($crv)) {
            // THE DIFFERENCE MUST BE A WRAPPER LOSS AND NOTHING ELSE. Without
            // this, the exemption would key on "the sidecar re-parses
            // differently" and would accept ANY tree change a future pinned
            // fixture carried - a dropped node, a reordering, a changed
            // attribute - as canonical. §1c licenses losing a WRAPPER, so that
            // is what is checked, as a property of the shapes rather than as a
            // list of slugs that would go stale on the next renumbering.
            $this->assertSame(
                self::shapeWithoutWrappers($crv),
                self::shapeWithoutWrappers($canonical),
                'the pinned canonical form for ' . $slug . ' differs from its source by more than '
                . 'a PART 11 §1c wrapper loss, so it is not a ceiling this exemption covers',
            );
            $this->assertSame(
                self::tree($canonical),
                $written,
                'fmt(x) does not parse to the spec canonical form\'s tree for ' . $slug,
            );

            return;
        }

        $this->assertSame(
            self::tree($crv),
            $written,
            'parse(fmt(x)) != parse(x) for ' . $slug,
        );
    }

    /**
     * THE PART 11 §1c EXEMPTION IS REACHED, so it cannot rot unnoticed.
     *
     * The per-document test above takes the exemption branch silently: if the
     * corpus or this engine changed so that no document's pinned canonical form
     * re-parsed differently from its source, every document would fall through
     * to the plain invariant, the whole branch would stop executing, and nothing
     * would say so. The carve-out would then sit in the file describing a
     * ceiling that no longer exists - which is how a guard stops guarding.
     *
     * Asked once, over the corpus, because it is a question about the corpus.
     * It fails in the direction the per-document `assertNotSame` could not: the
     * day a lone indented image round-trips cleanly, this goes red and both the
     * branch and this test are deleted together.
     *
     * The message names the documents rather than only counting them, so a
     * corpus renumbering reads as the rename it is.
     */
    public function testThePartElevenOneCCeilingIsReached(): void
    {
        $reached = [];
        foreach (self::pinnedProvider() as $slug => $case) {
            $canonical = self::pinnedCanonicalForm($slug);
            if ($canonical !== null && self::tree($canonical) !== self::tree($case['crv'])) {
                $reached[] = $slug;
            }
        }

        $this->assertNotSame(
            [],
            $reached,
            'no pinned canonical form re-parses differently from its source: the PART 11 §1c '
            . 'exemption in testTheFormattedDocumentParsesToTheSameTree is dead and should be deleted',
        );
    }

    /**
     * The render and the canonical tree of a document, as one comparable
     * string, or null when the document does not parse at all.
     *
     * Both halves are needed. The tree comparison forgives escaping - it has
     * to, or §1 contradicts §2 - so on its own it would call EVERY escape
     * idle. The render is what still separates an escape that changes the
     * document from one that changes nothing.
     */
    private static function escapeFingerprint(CarveConverter $converter, AstCodec $codec, string $source): ?string
    {
        try {
            $html = $converter->convert($source);
            $tree = (string)json_encode(self::canonical($codec->encode($converter->parse($source))));
        } catch (Throwable) {
            return null;
        }

        return $html . "\0" . $tree;
    }

    /**
     * A document's IDLE escapes, counted per escaped character - PART 11 §2's
     * "only if".
     *
     * Each backslash is removed on its own and the document re-measured. One
     * whose removal leaves BOTH the render and the canonical tree unchanged is
     * an escape the re-parse never needed, and it is counted under the byte it
     * was escaping.
     *
     * PER CHARACTER RATHER THAN A TOTAL, so that retiring one escape cannot pay
     * for inventing a different one - see {@see self::inventedIdleEscapes()}.
     *
     * A removal that makes the document unparseable is not idle: the
     * fingerprint is null, which matches nothing.
     *
     * @return array<string, int>
     */
    private static function idleEscapes(string $source): array
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $base = self::escapeFingerprint($converter, $codec, $source);
        if ($base === null) {
            return [];
        }

        $idle = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] !== '\\') {
                continue;
            }
            $without = substr($source, 0, $i) . substr($source, $i + 1);
            if (self::escapeFingerprint($converter, $codec, $without) !== $base) {
                continue;
            }
            $escaped = $i + 1 < $length ? $source[$i + 1] : '';
            $idle[$escaped] = ($idle[$escaped] ?? 0) + 1;
        }

        return $idle;
    }

    /**
     * The idle escapes the WRITER added, over the ones the author already had.
     *
     * Counting only fmt(x) would charge the writer for an escape the author
     * wrote and the writer merely carried through, so the same count is taken
     * on the source and subtracted.
     *
     * THE SUBTRACTION IS PER CHARACTER AND CLAMPED AT ZERO PER CHARACTER. A
     * document-wide total would let the writer pay for a newly invented escape
     * with an unrelated one it retired - drop two of the author's idle `.`
     * escapes, invent an idle `|`, and the net is negative while a new defect
     * is on the page. Per character, the invented `|` still counts. Clamping
     * per character is what keeps that sound: retiring an author's escape is
     * §2's job, not credit.
     *
     * What is left is a FLOOR, not an exact count: two idle escapes of the
     * SAME character, one retired and one invented in another place, still
     * cancel. Positional matching would close that, and nothing in the corpus
     * currently exercises it - the per-character count reproduces the seeded
     * 28 documents and 72 escapes exactly.
     */
    private static function inventedIdleEscapes(string $crv): int
    {
        return self::inventedIdleEscapesBetween($crv, CarveConverter::toCarve($crv));
    }

    /**
     * The same count between any two spellings, so the property above can be
     * demonstrated without going through the writer.
     */
    private static function inventedIdleEscapesBetween(string $source, string $formatted): int
    {
        if (!str_contains($source, '\\') && !str_contains($formatted, '\\')) {
            return 0;
        }

        $authored = self::idleEscapes($source);
        $invented = 0;
        foreach (self::idleEscapes($formatted) as $escaped => $count) {
            $invented += max(0, $count - ($authored[$escaped] ?? 0));
        }

        return $invented;
    }

    /**
     * The writer invents no escape the re-parse does not need (PART 11 §2).
     *
     * The equality invariant above cannot see this one: it forgives escaping
     * by construction, so an invented escape passes it, passes the HTML
     * comparison, passes idempotency and re-parses cleanly. Minimality is the
     * only property that would have caught carve-php#1520's doubled caret or
     * carve-php#1522's half-formed braced pair, and both of those were found
     * by a human reading output instead.
     *
     * Red on 28 of 1341 documents when this landed, so it is gated by the
     * shrink-only ratchet above rather than an allowlist - the entries are
     * known violations with a number attached, not blessings.
     */
    #[DataProvider('corpusProvider')]
    public function testTheWriterInventsNoEscapeTheReParseDoesNotNeed(string $slug, string $crv): void
    {
        $allowed = self::IDLE_ESCAPE_RATCHET[$slug][0] ?? 0;
        $invented = self::inventedIdleEscapes($crv);

        $message = $invented > $allowed
            ? 'the writer invented ' . $invented . ' escape(s) the re-parse does not need in ' . $slug
                . ', and the ratchet allows ' . $allowed
                . '. PART 11 §2 escapes a character only if omitting it would change the re-parse. '
                . 'The ratchet may only shrink, so this is a regression to fix, not an entry to raise.'
            : 'the ratchet entry for ' . $slug . ' is stale: it records ' . $allowed
                . ' invented escape(s) and the writer now emits ' . $invented
                . '. Lower the entry to ' . $invented . ' (or delete it at 0) so the debt cannot grow back into the slack.';

        $this->assertSame($allowed, $invented, $message);
    }

    /**
     * Every ratchet entry names a real document, a positive count and a cause.
     *
     * An entry with an empty reason is a build failure rather than an entry:
     * a list nobody has to justify is how an allowlist quietly becomes the
     * thing that hides the problem.
     */
    public function testEveryRatchetEntryCarriesACountAndAReason(): void
    {
        $corpus = self::corpusProvider();

        foreach (self::IDLE_ESCAPE_RATCHET as $slug => [$count, $reason]) {
            $this->assertArrayHasKey($slug, $corpus, 'the ratchet names a document the corpus does not have: ' . $slug);
            $this->assertGreaterThan(0, $count, 'a ratchet entry records no invented escape, so it is not debt: ' . $slug);
            $this->assertNotSame('', trim($reason), 'the ratchet entry for ' . $slug . ' has no reason, and an entry nobody can explain is the next thing to investigate');

            $named = false;
            foreach (self::IDLE_ESCAPE_CAUSES as $cause) {
                $named = $named || str_starts_with($reason, $cause);
            }
            $this->assertTrue($named, 'the ratchet entry for ' . $slug . ' names no measured cause: ' . $reason);
        }
    }

    /**
     * THE SWEEP CAN FAIL, and it fails on exactly what §2 forbids.
     *
     * Without this the whole check could be a count that is structurally
     * always zero - the shape carve-php#1523 exists to close. So both halves
     * are pinned: an escape that changes nothing is seen, and an escape that
     * is load-bearing is not counted against the writer.
     */
    public function testTheIdleSweepSeesAnInventedEscapeAndKeepsANeededOne(): void
    {
        // Idle: mid-line, a `>` is text with or without the backslash.
        $this->assertSame(['>' => 1], self::idleEscapes("a \\> b\n"));

        // Needed: at column zero, bare it opens a quote.
        $this->assertSame([], self::idleEscapes("\\> a\n"));

        // And the count is backslashes that do nothing, not backslashes.
        $this->assertSame([], self::idleEscapes("a > b\n"));
        $this->assertSame([], self::idleEscapes("a b\n"));

        // Per character, so a retired escape cannot pay for an invented one:
        // the same total, a different character, is still one invented escape.
        $this->assertSame(1, self::inventedIdleEscapesBetween("a \\. b\n", "a \\| b\n"));
        $this->assertSame(0, self::inventedIdleEscapesBetween("a \\. b\n", "a \\. b\n"));
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
