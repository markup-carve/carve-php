<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-144: the formatter keeps a next-line control comment directly above the line it suppresses (C2-B-216).
 */
final class LintControlLinesTest extends TestCase
{
    /**
     * @var string
     */
    private const CONTROL = '%% carve-lint-disable-next-line';

    public function testNoBlankLineBetweenTheCommentAndTheBlockItSuppresses(): void
    {
        $c = self::CONTROL;
        self::assertSame("para\n\n$c\n== Heading\n", Carve::format("para\n\n$c\n## Heading\n"));
        self::assertSame("x\n\n$c\n| a | b |\n", Carve::format("x\n\n$c\n|a|b|\n"));
        self::assertSame("> x\n>\n> $c\n> > q\n", Carve::format("> x\n>\n> $c\n> > q\n"));
        self::assertSame("- a\n\n  $c\n- b\n", Carve::format("- a\n\n$c\n- b\n"));
    }

    public function testACommentThatSuppressesNothingKeepsItsBlankLine(): void
    {
        $c = self::CONTROL;
        self::assertSame("x\n\n$c\n\n== See [a]()\n", Carve::format("x\n\n$c\n\n## See [a]()\n"));
        self::assertSame("- a\n\n  $c\n\n- b\n", Carve::format("- a\n\n$c\n\n- b\n"));
        // C2-W-114 writes no blank line between two descriptions; this one stays, or the comment would suppress `: [a]()`.
        self::assertSame(":: t\n: d\n\n  $c\n\n: [a]()\n", Carve::format(":: t\n: d\n\n$c\n\n: [a]()\n"));
    }

    public function testTheBlankLineStaysWhereLeavingItOutChangesTheTree(): void
    {
        $c = self::CONTROL;
        self::assertSame("a $c\n\nb\n", Carve::format("a $c\n\nb\n"));
        self::assertSame("```\n$c\n\nx\n```\n", Carve::format("```\n$c\n\nx\n```\n"));
    }

    public function testACrlfBlankLineBelowTheCommentStays(): void
    {
        $c = self::CONTROL;
        self::assertSame("x\n\n$c\n\n== See [a]()\n", Carve::format("x\r\n\r\n$c\r\n\r\n## See [a]()\r\n"));
    }

    public function testACommentThatHoldsTheKeywordLaterIsNoControl(): void
    {
        $c = self::CONTROL;
        self::assertSame("x\n\n%% foo $c\n\n== H\n", Carve::format("x\n\n%% foo $c\n## H\n"));
    }

    public function testASuppliedTreeHasTheCommentDirectlyAboveTheNextBlock(): void
    {
        $c = self::CONTROL;
        self::assertSame("x\n\n$c\n== See [a]()\n", Carve::write(Carve::parse("x\n\n$c\n\n## See [a]()\n")));
    }

    public function testFmtThenLintSuppressesWhatLintSuppresses(): void
    {
        $finding = '[a]()';
        $targets = [
            "para $finding", "== H $finding", "| $finding | b |", "- $finding", "> $finding", ": $finding",
            "::: note\n$finding\n:::", "1. $finding", "{.c}\npara $finding",
        ];
        $befores = ['', "x\n\n", "== T\n\n", "- a\n\n", "| a | b |\n\n", "```\nc\n```\n\n", "> q\n\n", "%% other\n", ":: t\n: d\n\n"];
        $wraps = [
            static fn (string $s): string => $s,
            static fn (string $s): string => implode("\n", array_map(
                static fn (string $l): string => $l === '' ? '>' : "> $l",
                explode("\n", $s),
            )),
            static fn (string $s): string => implode("\n", array_map(
                static fn (string $l, int $i): string => $l === '' ? '' : ($i === 0 ? '- ' : '  ') . $l,
                explode("\n", $s),
                array_keys(explode("\n", $s)),
            )),
            static fn (string $s): string => ":::: w\n$s\n::::",
        ];
        $checked = 0;
        foreach ($wraps as $wrap) {
            foreach ($befores as $before) {
                foreach ($targets as $target) {
                    foreach (['', "\n"] as $blank) {
                        $source = $wrap($before . self::CONTROL . "\n" . $blank . $target) . "\n";
                        $formatted = Carve::format($source);
                        self::assertTrue(TreeEquality::equal(
                            TreeEncoder::decoded(Carve::parse($formatted)),
                            TreeEncoder::decoded(Carve::parse($source)),
                        ), $source);
                        self::assertSame($formatted, Carve::format($formatted), $source);
                        self::assertSame(self::findings($source), self::findings($formatted), $source);
                        $checked++;
                    }
                }
            }
        }
        self::assertGreaterThan(600, $checked);
    }

    /**
     * The lint findings of `$source` by rule, outside the parse channel.
     *
     * @return array<string, int>
     */
    private static function findings(string $source): array
    {
        $found = [];
        foreach (Carve::lint($source) as $entry) {
            if ($entry->channel !== 'parse') {
                $found[$entry->code] = ($found[$entry->code] ?? 0) + 1;
            }
        }
        ksort($found);

        return $found;
    }
}
