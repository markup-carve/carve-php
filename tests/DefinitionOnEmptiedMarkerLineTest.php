<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition collected from a nested item's marker line leaves that item
 * empty. The writer must restore the definition to that line; an empty-item
 * continuation marker would capture the outer item's following content.
 */
class DefinitionOnEmptiedMarkerLineTest extends TestCase
{
    /**
     * Shapes whose canonical form is the source itself: the definition goes back
     * on the marker line it was authored on.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function markerLineShapes(): iterable
    {
        yield 'reference definition followed by a definition description' => ["* * [d]: u\n  :\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'reference definition followed by text' => ["* * [d]: u\n  x\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'dash markers' => ["- - [d]: u\n  tail\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'footnote definition' => ["* * [^f]: n\n  :\n", "See [^f].\n", 'id="fnref1"'];
        yield 'nothing follows the emptied item' => ["* * [d]: u\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'nested inside a div' => ["::: n\n* * [d]: u\n  :\n:::\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'three nested levels' => ["* * * [d]: u\n    :\n", "See [x][d].\n", '<a href="u">x</a>'];
        yield 'multiple following continuation lines' => ["* * [d]: u\n  :\n  more\n", "See [x][d].\n", '<a href="u">x</a>'];
    }

    /**
     * The TOP-LEVEL emptied item keeps the older canonical form, `- +`, which
     * corpus fixtures 16-reference-link-4 and
     * 117-footnote-definition-inside-a-container-is-collected-2 pin. It
     * round-trips there because nothing follows at a shallower column for the
     * marker to capture, and the restoring branch must not reach it - a first
     * draft keyed on the sibling COUNT rather than on depth, which rewrote both
     * fixtures' form for any list holding two items.
     */
    public function testATopLevelEmptiedItemKeepsTheContinuationMarkerForm(): void
    {
        $converter = CarveConverter::create();
        $formatted = $converter->toCarve("- a\n- [d]: u\n");

        $this->assertSame("- a\n- +\n\n[d]: u\n", $formatted, 'the canonical bytes must match carve-js');
        $this->assertSame($formatted, $converter->toCarve($formatted), 'fmt must be idempotent');
        $this->assertSame(
            $converter->convert("- a\n- [d]: u\n"),
            $converter->convert($formatted),
            'HTML must survive fmt',
        );
        $this->assertStringContainsString(
            '<a href="u">x</a>',
            $converter->convert($formatted . "\nSee [x][d].\n"),
            'the definition must still resolve after the round trip',
        );
    }

    #[DataProvider('markerLineShapes')]
    public function testDefinitionIsRestoredOnItsMarkerLine(
        string $source,
        string $reference,
        string $resolvedHtml,
    ): void {
        $converter = CarveConverter::create();
        $formatted = $converter->toCarve($source);

        $this->assertSame($source, $formatted, 'the canonical bytes must match carve-js');
        $this->assertSame($formatted, $converter->toCarve($formatted), 'fmt must be idempotent');
        $this->assertSame($converter->convert($source), $converter->convert($formatted), 'HTML must survive fmt');
        $this->assertStringContainsString(
            $resolvedHtml,
            $converter->convert($formatted . "\n" . $reference),
            'the restored definition must still resolve after the round trip',
        );
    }
}
