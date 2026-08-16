<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function count;
use function file_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function preg_match_all;
use function sprintf;
use function str_replace;

/**
 * The shared escaper corpus (`tests/spec/tests/corpus-escape/`), run against
 * `escapePlainCarveInlineSyntax` under every profile this engine can produce.
 *
 * The conformance corpus pairs a `.crv` input with expected output per render
 * target, so everything that READS Carve is gated. Nothing gated what WRITES
 * it: the converters' escaper is one rule with four independent spellings
 * across the org, and markup-carve/carve#1130 lists six times a fix landed in
 * one engine and not the others, four of them inside this function. The
 * question a case asks needs no semantic comparison - "this text, escaped, is
 * exactly this Carve source" - so the expectations are byte-exact.
 *
 * Mirrors the runner carve-rs added in markup-carve/carve-rs#998.
 */
class EscaperCorpusTest extends TestCase
{
    /**
     * @var string
     */
    protected const CORPUS = __DIR__ . '/../../spec/tests/corpus-escape/cases.json';

    /**
     * Read once. Every test below asks the same file the same question.
     *
     * @throws \RuntimeException
     *
     * @return array{profiles: array<string, array<string, string>>, cases: array<int, array{name: string, input: string, expected: array<string, string>}>}
     */
    protected static function corpus(): array
    {
        if (!file_exists(self::CORPUS)) {
            throw new RuntimeException(
                'Escaper corpus not found at ' . self::CORPUS . ".\n"
                . "Initialize the submodule:\n  git submodule update --init",
            );
        }

        $decoded = json_decode((string)file_get_contents(self::CORPUS), true);
        if (!is_array($decoded) || !isset($decoded['cases'], $decoded['profiles'])) {
            throw new RuntimeException('Escaper corpus at ' . self::CORPUS . ' is not the expected shape.');
        }

        /** @var array{profiles: array<string, array<string, string>>, cases: array<int, array{name: string, input: string, expected: array<string, string>}>} $decoded */
        return $decoded;
    }

    /**
     * The profiles THIS engine can produce, by the corpus's names.
     *
     * All three have a real call site here, which is why all three run.
     * `testEveryProfileNamedHereHasACallSite` is what keeps that true.
     *
     * @return array<string, array<string, string>>
     */
    protected static function profiles(): array
    {
        return EscaperHarness::profiles();
    }

    /**
     * AN EMPTY CORPUS IS NOT A PASS.
     *
     * Every sweep below iterates the case list, so a corpus that failed to load
     * would report success having compared nothing - which is the state this
     * rule was already in before it was wired.
     */
    public function testACaseIsRead(): void
    {
        $cases = self::corpus()['cases'];

        $this->assertGreaterThanOrEqual(50, count($cases), 'the corpus reported ' . count($cases) . ' cases');
    }

    /**
     * The handled sets are spelled in two places - the trait's constants and
     * the corpus - and a silent drift between them would leave every case below
     * passing while measuring the wrong question.
     */
    public function testTheHandledSetsMatchTheCorpusProfiles(): void
    {
        $declared = self::corpus()['profiles'];

        foreach (self::profiles() as $name => $handled) {
            $this->assertArrayHasKey($name, $declared, "the corpus declares no profile {$name}");
            $this->assertSame(
                $declared[$name]['braced'] ?? '',
                $handled['braced'] ?? '',
                "{$name}: braced handled set",
            );
            $this->assertSame(
                $declared[$name]['bare'] ?? '',
                $handled['bare'] ?? '',
                "{$name}: bare handled set",
            );
        }
    }

    /**
     * @param string $input
     * @param array<string, string> $handled
     * @param string $expected
     */
    #[DataProvider('escaperCaseProvider')]
    public function testEveryCaseMatchesUnderEveryProfile(string $input, array $handled, string $expected): void
    {
        $this->assertSame($expected, EscaperHarness::escape($input, $handled));
    }

    /**
     * One data set per (case, profile) pair, keyed so a failure names the case
     * rather than an index.
     *
     * @throws \RuntimeException
     *
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function escaperCaseProvider(): array
    {
        $corpus = self::corpus();
        $profiles = self::profiles();

        $sets = [];
        foreach ($corpus['cases'] as $case) {
            foreach ($case['expected'] as $profile => $expected) {
                // An engine skips a profile it cannot produce, the way the
                // render corpus skips a target it does not implement. This
                // engine produces all three, so nothing is skipped today.
                if (!isset($profiles[$profile])) {
                    continue;
                }

                $sets[$case['name'] . ' [' . $profile . ']'] = [$case['input'], $profiles[$profile], $expected];
            }
        }

        if ($sets === []) {
            throw new RuntimeException('The escaper corpus produced no case-profile pairs.');
        }

        return $sets;
    }

    /**
     * The corpus's own invariant, restated against THIS implementation.
     *
     * Escaping only ever INSERTS backslashes, so an output with every backslash
     * removed must equal the input exactly. Asserted on the OUTPUT rather than
     * on the fixture, because on the fixture it only catches a fabricated
     * expectation; on the output it also catches an escaper that rewrites text
     * instead of freezing it, which no byte-exact expectation can distinguish
     * from a correct one it happens to match.
     */
    public function testEscapingOnlyEverInsertsBackslashes(): void
    {
        foreach (self::corpus()['cases'] as $case) {
            foreach (self::profiles() as $profile => $handled) {
                $escaped = EscaperHarness::escape($case['input'], $handled);
                $this->assertSame(
                    $case['input'],
                    str_replace('\\', '', $escaped),
                    sprintf('%s [%s] rewrote its input', $case['name'], $profile),
                );
            }
        }
    }

    /**
     * The handled set is what SEPARATES the profiles, and a set that quietly
     * went back to being hardwired would make every case above pass while every
     * profile gave the same answer.
     *
     * Each pair below is one delimiter that a source language owns and plain
     * text does not, so the two answers must differ.
     */
    public function testTheProfilesActuallyDiffer(): void
    {
        $profiles = self::profiles();

        $this->assertSame('a *x* b', EscaperHarness::escape('a *x* b', $profiles['djot']));
        $this->assertSame('a \\*x* b', EscaperHarness::escape('a *x* b', $profiles['plain']));
        $this->assertSame('a ~x~ b', EscaperHarness::escape('a ~x~ b', $profiles['markdown']));
        $this->assertSame('a \\~x~ b', EscaperHarness::escape('a ~x~ b', $profiles['plain']));
        $this->assertSame('a {=x=} b', EscaperHarness::escape('a {=x=} b', $profiles['djot']));
        $this->assertSame('a \\{\\=x=} b', EscaperHarness::escape('a {=x=} b', $profiles['plain']));
    }

    /**
     * NO PROFILE HERE IS SPECULATIVE.
     *
     * carve-rs runs `plain` and `markdown` with no caller, because its Markdown
     * and HTML importers build an AST and let the canonical writer emit source.
     * All three profiles have a text-level converter in this engine, and this is
     * what says so: a call site rewritten back to an inline literal, or removed,
     * drops the count to zero and fails here rather than leaving the corpus
     * silently measuring a set nothing passes.
     */
    public function testEveryProfileNamedHereHasACallSite(): void
    {
        $callers = [
            'HANDLED_PLAIN' => ['src/Converter/BbcodeToCarve.php', 'src/Converter/HtmlToCarve.php'],
            'HANDLED_MARKDOWN' => ['src/Converter/MarkdownToCarve.php'],
            'HANDLED_DJOT' => ['src/Converter/DjotToCarve.php'],
        ];

        foreach ($callers as $constant => $files) {
            foreach ($files as $file) {
                $source = (string)file_get_contents(__DIR__ . '/../../../' . $file);
                $this->assertGreaterThan(
                    0,
                    preg_match_all('/self::' . $constant . '\b/', $source),
                    "{$file} passes no {$constant}",
                );
            }
        }
    }

    /**
     * STATED, NOT ASSERTED AS CORRECT, and the one place this engine and
     * carve-rs are known to differ on a shape the corpus does not pin.
     *
     * A space AFTER the delimiter is where the "does this run OPEN?" test
     * bites; the corpus's only inner-space case is `a { ^x^ } b`, whose space
     * sits between the `{` and the delimiter, so the opener is never matched at
     * all and every answer passes. This engine freezes the opener, so the text
     * stays literal. carve-rs leaves it bare and pins that (see
     * `a_delimiter_with_a_space_against_it_is_where_the_opener_test_bites` in
     * its `src/djot_migrate.rs`), where its own comment calls the result a leak
     * and declines to move on the grounds that carve-php has the same boundary.
     * It does not. Pinned here so the divergence is a measured fact rather than
     * an assumption either side is making about the other; settling it wants a
     * corpus case and all three engines, not a unilateral change.
     */
    public function testAnOpenerWithASpaceAgainstItsDelimiterIsFrozenHere(): void
    {
        $plain = self::profiles()['plain'];

        // The space is before the delimiter: no opener, nothing to freeze.
        $this->assertSame('a { ^x^ } b', EscaperHarness::escape('a { ^x^ } b', $plain));
        // The space is after it: the run opens, so the brace is frozen and the
        // literal text stays literal instead of rendering `a <sup> x</sup> b`.
        $this->assertSame('a \\{^ x^} b', EscaperHarness::escape('a {^ x^} b', $plain));
        $this->assertSame('a \\{^ x b', EscaperHarness::escape('a {^ x b', $plain));
    }
}
