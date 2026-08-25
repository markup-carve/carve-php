<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\TestCase\CorpusPopulation;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 7: `fmt` never emits a line whose only content is ASCII space
 * or tab. Such a line is emitted empty.
 *
 * A whitespace-only line is not stable. Editors that strip trailing whitespace
 * on save, `git apply --whitespace=fix` and CI whitespace checks all rewrite it,
 * so a formatter emitting one produces output that ordinary tooling changes
 * behind it, and then reports a diff on a file nobody edited (carve#375).
 *
 * Swept over the whole corpus rather than pinned per case: the reported shape
 * was a list item, and this engine had the same defect in definition lists,
 * footnote continuations and nested lists - 23 lines across 18 files.
 *
 * Three things section 7 does NOT cover, and this sweep must not either:
 * whitespace at the end of a line that HAS content (it can be document content,
 * and stripping it before a soft break changed rendered output in carve#359), a
 * trailing no-break space (content the author wrote), and whitespace that IS
 * verbatim content, since three spaces inside a code block render as three
 * spaces.
 */
class NoWhitespaceOnlyLineTest extends TestCase
{
    /**
     * Sites the sweep still tolerates. Listed rather than filtered out of it,
     * so they stay visible.
     *
     * EMPTY, and the two guards below are why that state is trustworthy rather
     * than merely current.
     *
     * The one entry that stood here named `73-list-nesting-and-looseness-5.crv:3`
     * - a fenced block in a list item whose indentation sentinel hid the
     * structural indent on a line with no verbatim content. Upstream renumbered
     * that document to 75, so the entry named no corpus file at all and excused
     * nothing, and the renumbered document emits no such line either. It was
     * dead in BOTH directions and nothing here could say so
     * (markup-carve/carve-php#1687).
     *
     * Nothing could, because the list was consulted exactly once, to suppress a
     * failure. There was no orphan guard and no staleness half - the only list
     * in the three engines wired in neither direction. Its carve-js twin has
     * both, which is why that engine's copy was emptied when the renumbering
     * landed and this one was not.
     *
     * The deletion makes the ledger honest today. The guards are what stop the
     * same finding recurring the next time a corpus document is renumbered.
     *
     * @var array<string>
     */
    private const KNOWN_REMAINING = [];

    /**
     * @return array<string>
     */
    private function offendingLines(string $slug, string $out): array
    {
        $found = [];
        foreach (explode("\n", $out) as $i => $line) {
            if ($line !== '' && trim($line, " \t") === '') {
                $found[] = $slug . ':' . ($i + 1);
            }
        }

        return $found;
    }

    /**
     * Every site the writer produces over the whole corpus.
     *
     * ONE PASS, read by both directions below, so the two questions are
     * answered over the same run rather than over two sweeps that could
     * disagree about what the corpus even is.
     *
     * @return array<string>
     */
    private function producedSites(): array
    {
        $dir = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $inputs = glob($dir . '/*.crv') ?: [];
        $this->assertSame(
            CorpusPopulation::expectedSize(),
            count($inputs),
            'the corpus is truncated',
        );

        $produced = [];
        foreach ($inputs as $path) {
            $slug = basename($path);
            $out = CarveConverter::toCarve((string)file_get_contents($path));
            foreach ($this->offendingLines($slug, $out) as $site) {
                $produced[$site] = true;
            }
        }

        return array_keys($produced);
    }

    public function testTheWriterNeverEmitsAWhitespaceOnlyLine(): void
    {
        $failures = array_values(array_diff($this->producedSites(), self::KNOWN_REMAINING));
        sort($failures);

        $this->assertSame([], $failures, "fmt emitted whitespace-only line(s):\n" . implode("\n", $failures));
    }

    /**
     * A SITE THAT NAMES NO CORPUS FILE IS NOT AN EXEMPTION.
     *
     * Corpus files carry the spec's ordering number, which shifts whenever a
     * section is inserted upstream, so an entry here goes stale without
     * anything in the diff saying so. The sweep only ever consults this list
     * for a site it actually produced, so such an entry is consulted never,
     * excuses nothing, and still reads as a live carve-out. This is the guard
     * whose absence let `73-...` sit here after the document became `75-...`.
     */
    public function testKnownRemainingNamesOnlyCorpusFilesThatExist(): void
    {
        $dir = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $present = array_map('basename', glob($dir . '/*.crv') ?: []);

        // MAP THEN DIFF, rather than a foreach or an array_filter. With the
        // list empty PHPStan narrows it to `array{}` and objects that any
        // filter over it can have no effect - the same "this cannot fail"
        // signal the guard itself exists to raise, and it is right about the
        // static fact. `array_diff` widens the type, so the guard reads as
        // live code whether or not anything is declared, which is how the
        // other empty ledgers in this suite are written.
        $declaredFiles = array_map(
            static fn (string $site): string => substr($site, 0, (int)strrpos($site, ':')),
            self::KNOWN_REMAINING,
        );
        $orphaned = array_values(array_diff($declaredFiles, $present));

        $this->assertSame(
            [],
            $orphaned,
            'renumbered upstream, or already retired - either way the entry excuses nothing: '
                . implode(', ', $orphaned),
        );
    }

    /**
     * A SITE THAT IS NO LONGER PRODUCED IS NOT AN EXEMPTION EITHER.
     *
     * The orphan guard above catches an entry whose FILE is gone. It cannot
     * catch the other way - a file that still exists and stopped emitting the
     * line - because `in_array` can only ever suppress a failure. Both halves
     * are needed: the retired entry was dead by BOTH tests, and either one
     * alone would have caught it.
     */
    public function testKnownRemainingIsStillBehindOnWhatItClaims(): void
    {
        $stale = array_values(array_diff(self::KNOWN_REMAINING, $this->producedSites()));
        sort($stale);

        $this->assertSame(
            [],
            $stale,
            'no longer emitted - delete the KNOWN_REMAINING entry in the same commit that proves it: '
                . implode(', ', $stale),
        );
    }

    public function testABlankLineInsideAListItemIsEmpty(): void
    {
        $source = "1. one\n\n    > q\n";
        $out = CarveConverter::toCarve($source);

        $this->assertSame("1. one\n   > q\n", $out);
        $this->assertSame([], $this->offendingLines('inline', $out));
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert($out));
        $this->assertSame($out, CarveConverter::toCarve($out), 'fmt is not idempotent');
    }

    public function testWhitespaceThatIsVerbatimContentSurvives(): void
    {
        // Three spaces inside a code block are data, not layout.
        $source = "```\na\n   \nb\n```\n";
        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
