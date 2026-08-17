<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EVERY three-line line block over a 24-shape alphabet, against PART 11 §1.
 *
 * The seven corpus documents that arrived with carve#1333 and carve#1334 pin
 * ONE defect each. The writer rules they state are decided per LINE and read
 * against the line NEXT to it, so the shapes that break them are combinations -
 * a comment above an unclosed run, a backslash-only line as a stanza's last, a
 * trailing space where the following line is a comment. A fixture set names a
 * handful of those; a sweep over the product names all of them.
 *
 * This is the method carve-js used to find four defects the seven fixtures do
 * not pin, and it is what this file exists to keep closed. The alphabet holds
 * the line shapes the two rulings turn on: a comment line in its spellings, a
 * backslash line, a trailing space with and without a backslash, a medial gap,
 * an unclosed and a closed verbatim run, an escaped marker, an indented line.
 *
 * THREE PROPERTIES, and the first is the one no rendering can see:
 *
 *  - `parse(fmt(x))` holds the same comments as `parse(x)`. A writer that
 *    changes a comment's body, drops the node, or publishes its text is caught
 *    here and NOWHERE in the HTML, because a comment renders nothing either way.
 *  - `toHtml(fmt(x)) == toHtml(x)`, byte for byte.
 *  - `fmt(fmt(x)) == fmt(x)`.
 *
 * In the `scaling` group because the product is a few thousand documents: it is
 * a sweep rather than a unit, and the everyday suite keeps the fixtures.
 */
#[Group('scaling')]
class LineBlockWriterSweepTest extends TestCase
{
    /**
     * The line shapes the two rulings turn on.
     *
     * @var list<string>
     */
    private const ALPHABET = [
        'a',
        '',
        '\\',
        'a \\',
        'a  \\',
        'a ',
        'a  ',
        'a\\ ',
        '%% c',
        '%%',
        '%%c',
        '  %% c',
        '\\%% c',
        'x %% c',
        'a `b',
        'b` c',
        '`a`',
        'a $`x',
        'a !`x',
        '%%%',
        'a\\',
        ' a',
        'a\\ b',
        'a\\\\',
    ];

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function stanzaProvider(): array
    {
        $cases = [];
        foreach (self::ALPHABET as $first) {
            $rows = [];
            foreach (self::ALPHABET as $second) {
                foreach (self::ALPHABET as $third) {
                    $rows[] = [$first, $second, $third];
                }
            }
            // One data set per FIRST line, so the provider stays at 24 rows
            // while the sweep still covers the full product. A data set per
            // document would spend more time building the provider than
            // running the assertions.
            $cases['first line ' . var_export($first, true)] = [$rows];
        }

        return $cases;
    }

    /**
     * @param list<list<string>> $stanzas
     */
    #[DataProvider('stanzaProvider')]
    public function testTheWriterHoldsTheInvariantOnEveryThreeLineStanza(array $stanzas): void
    {
        $converter = new CarveConverter();
        $checked = 0;

        foreach ($stanzas as $lines) {
            $source = "::: |\n" . implode("\n", $lines) . "\n:::\n";
            $formatted = CarveConverter::toCarve($source);

            $this->assertSame(
                self::comments($converter->parse($source)),
                self::comments($converter->parse($formatted)),
                'the writer changed a comment for: ' . var_export($source, true),
            );
            $this->assertSame(
                $converter->convert($source),
                $converter->convert($formatted),
                'fmt changed the rendering of: ' . var_export($source, true),
            );
            $this->assertSame(
                $formatted,
                CarveConverter::toCarve($formatted),
                'fmt is not idempotent on: ' . var_export($source, true),
            );
            $checked++;
        }

        // The sweep ran. A provider that quietly built nothing would otherwise
        // pass by asserting nothing, which is the failure mode a generated
        // suite has and a fixture suite does not.
        $this->assertSame(count(self::ALPHABET) ** 2, $checked);
    }

    /**
     * Every comment body in the tree, in document order.
     *
     * @return list<string>
     */
    private static function comments(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Comment) {
                $found[] = $child->getContent();
            }
            foreach (self::comments($child) as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }
}
