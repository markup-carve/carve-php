<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\ContainerPrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The container prefix rule used to be spelled once per caller, so this pins
 * the collapsed spelling against the regexes the callers held before
 * (markup-carve/carve-php#961). A future change to the rule has to break these
 * on purpose; it can no longer change one caller and leave the rest deciding
 * the old way.
 */
class ContainerPrefixTest extends TestCase
{
    /**
     * Every shape the three spellings disagreed about, plus the ordinary ones.
     *
     * @return array<string, array{0: string}>
     */
    public static function lineProvider(): array
    {
        $lines = [
            '',
            '>',
            '> ',
            '>  ',
            '> a',
            '>  a',
            '>a',
            ">\t",
            ">\ta",
            '>>',
            '>> a',
            '> > a',
            '> > > a',
            '> >a',
            '>> ',
            ' > a',
            "\t> a",
            'a > b',
            '  > [r]: /u',
            '- a',
            '> - a',
            '> [^f]: note',
            '> ```',
            '>```',
        ];

        $cases = [];
        foreach ($lines as $line) {
            $cases[json_encode($line, JSON_THROW_ON_ERROR)] = [$line];
        }

        return $cases;
    }

    #[DataProvider('lineProvider')]
    public function testTheStrictRuleMatchesTheRegexesItReplaced(string $line): void
    {
        $expected = null;
        if (preg_match('/^> (.*)$/s', $line, $m) === 1) {
            $expected = $m[1];
        } elseif ($line === '>') {
            $expected = '';
        }

        $this->assertSame($expected, ContainerPrefix::quoteContent($line));
    }

    #[DataProvider('lineProvider')]
    public function testTheRepeatedStrictRuleMatchesTheRegexItReplaced(string $line): void
    {
        $this->assertSame(
            preg_replace('/^(?:>(?: |$))+/', '', $line),
            ContainerPrefix::stripQuoteMarkers($line),
        );
    }

    #[DataProvider('lineProvider')]
    public function testTheLooseRuleMatchesTheRegexItReplaced(string $line): void
    {
        $expected = ($line[0] ?? '') === '>'
            ? preg_replace('/^> ?/', '', $line)
            : null;

        $this->assertSame($expected, ContainerPrefix::looseQuoteContent($line));
    }

    /**
     * The `preg_match('/^> ?/', $line)` re-test the four prepass gates ran
     * after their own `$line[0] === '>'` byte test could not fail: the pattern
     * matches every string whose first byte is `>`. Deleting it removed a check
     * that decided nothing, which is why no test changed when it went.
     */
    #[DataProvider('lineProvider')]
    public function testTheDeletedGateReTestCouldNeverHaveFailed(string $line): void
    {
        if (($line[0] ?? '') !== '>') {
            $this->assertNotSame('>', $line[0] ?? '');

            return;
        }

        $this->assertSame(1, preg_match('/^> ?/', $line));
    }

    /**
     * The two rules are kept apart on purpose. `>text` is the ONLY shape they
     * answer differently, and the split is pre-existing - the strict rule is
     * the language's, the loose one is what the line-based prepasses have
     * always applied when deciding which region a line is in.
     */
    public function testTheTwoRulesDisagreeOnlyOnAMarkerWithNoSpace(): void
    {
        $disagreements = [];
        foreach (self::lineProvider() as [$line]) {
            if (ContainerPrefix::quoteContent($line) !== ContainerPrefix::looseQuoteContent($line)) {
                $disagreements[] = $line;
            }
        }

        $this->assertSame(['>a', ">\t", ">\ta", '>>', '>> a', '>> ', '>```'], $disagreements);
    }

    public function testTheContentColumnViewIsMeasuredInBytes(): void
    {
        $this->assertNull(ContainerPrefix::atContentColumn('> a', 0));
        $this->assertNull(ContainerPrefix::atContentColumn('> a', 2));
        $this->assertSame('> a', ContainerPrefix::atContentColumn('  > a', 2));
        $this->assertSame(' > a', ContainerPrefix::atContentColumn('  > a', 1));
        $this->assertNull(ContainerPrefix::atContentColumn('  > a', 3));
        $this->assertSame('> a', ContainerPrefix::atContentColumn("\t\t> a", 2));
    }
}
