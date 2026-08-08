<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
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
     * A fenced block inside a list item has its indentation sentinel-protected
     * so normalization cannot eat real code indentation, which also hides the
     * structural indent on a line whose verbatim content is empty. carve-js and
     * carve-rs have the same site. Listed rather than filtered out of the sweep,
     * so it stays visible.
     *
     * @var array<string>
     */
    private const KNOWN_REMAINING = [
        // The ONE remaining site, and the reason it is not the footnote and
        // definition class carve-php#1068 fixed: those two indented a fenced
        // block without the list writer's blank-continuation rule, and now share
        // it. This row is inside a LIST, which has always had that rule - it is
        // the case where the item's own indentation protection does not reach.
        '73-list-nesting-and-looseness-5.crv:3',
    ];

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

    public function testTheWriterNeverEmitsAWhitespaceOnlyLine(): void
    {
        $dir = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $inputs = glob($dir . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($inputs), 'the corpus was not found');

        $failures = [];
        foreach ($inputs as $path) {
            $slug = basename($path);
            $out = CarveConverter::toCarve((string)file_get_contents($path));
            foreach ($this->offendingLines($slug, $out) as $site) {
                if (!in_array($site, self::KNOWN_REMAINING, true)) {
                    $failures[] = $site;
                }
            }
        }

        $this->assertSame([], $failures, "fmt emitted whitespace-only line(s):\n" . implode("\n", $failures));
    }

    public function testABlankLineInsideAListItemIsEmpty(): void
    {
        $source = "1. one\n\n    > q\n";
        $out = CarveConverter::toCarve($source);

        $this->assertStringContainsString("\n\n", $out, 'expected an empty blank line');
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
