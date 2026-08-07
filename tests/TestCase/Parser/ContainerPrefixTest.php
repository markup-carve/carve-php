<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\ContainerPrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use function array_map;
use function in_array;
use function preg_match;
use function preg_replace;
use function sort;

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
     * The retired LOOSE spelling - `preg_replace('/^> ?/', ...)` behind a
     * `$line[0] === '>'` test - admitted these shapes as block-quote markers
     * while the block parser, reading the strict rule, refused them. Refusing
     * them everywhere is the whole of the behavior change: a prepass can no
     * longer harvest a definition out of a line the block parser prints as
     * prose (markup-carve/carve-php#961).
     *
     * Pinned as a list rather than as a count, so re-widening the prepass rule
     * has to edit this on purpose.
     */
    #[DataProvider('lineProvider')]
    public function testEveryShapeTheRetiredLooseRuleAdmittedIsNowRefused(string $line): void
    {
        $looselyAdmitted = ['>a', ">\t", ">\ta", '>>', '>> a', '>> ', '>```'];

        $looseContent = ($line[0] ?? '') === '>'
            ? preg_replace('/^> ?/', '', $line)
            : null;

        if (!in_array($line, $looselyAdmitted, true)) {
            // A CONTROL: the two spellings always agreed here, and still do.
            $this->assertSame($looseContent, ContainerPrefix::quoteContent($line));

            return;
        }

        $this->assertNotNull($looseContent);
        $this->assertNull(ContainerPrefix::quoteContent($line));
    }

    /**
     * There is exactly ONE marker rule to ask. A second public spelling on this
     * class is what let the prepasses and the block parser answer differently
     * in the first place, so its absence is asserted rather than assumed.
     */
    public function testTheClassSpellsTheMarkerRuleOnce(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(ContainerPrefix::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $this->assertSame(
            ['atContentColumn', 'quoteContent', 'quoteStages', 'stripQuoteMarkers'],
            $methods,
        );
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
