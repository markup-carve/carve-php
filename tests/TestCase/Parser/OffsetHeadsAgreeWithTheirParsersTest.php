<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\ContainerPrefix;
use MarkupCarve\Carve\Parser\ReferenceDefinitionExtractor;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use PHPUnit\Framework\TestCase;

/**
 * Each offset HEAD accepts a line exactly where its own parser can.
 *
 * The trailing-block tracker reads a line from a byte OFFSET rather than
 * cutting the tail out of its container prefix, because cutting copies the rest
 * of the line once per level and that is what made a line alternating a quote
 * marker with a bullet quadratic (carve-php#1437). The predicates it consults
 * cannot all be asked at an offset, so each one grew a HEAD - the same first
 * byte its own fast exit reads - and the tracker asks the head before it builds
 * anything.
 *
 * THAT MAKES THE HEAD A SECOND SPELLING, which is the failure carve-php#969
 * was: a byte test written twice, and a container-model change applied to one
 * of them. It is spelled twice deliberately - routing each parser's own fast
 * exit through its head measured about 5 percent on an ordinary document,
 * because those exits run on nearly every line the parser reads - so this test
 * is what stops the two drifting.
 *
 * THE ORACLE IS THE PARSER, not a table of bytes. A table would encode the same
 * reading twice and pass on a shared mistake. The property asserted is the only
 * one the tracker relies on and the only one that can be wrong in a direction
 * that matters: WHEREVER THE PARSER ACCEPTS, THE HEAD MUST ACCEPT. A head that
 * is merely wider costs a substring and decides nothing; a head that is
 * narrower silently deletes a branch, which is the whole class of defect here.
 *
 * Every byte value is tried, in four framings, so a head that reads the wrong
 * byte or forgets the blank run in front of it is caught rather than argued
 * about. The counts are asserted too: a corpus that stopped reaching the
 * accepting side would leave every "wherever it accepts" check vacuous, which
 * is the dead-check shape markup-carve/carve#755 catalogs.
 */
class OffsetHeadsAgreeWithTheirParsersTest extends TestCase
{
    /**
     * One line per byte value in four framings, plus the shapes each parser
     * actually accepts so the sweep is not only exercising refusals.
     *
     * @return array<string>
     */
    private static function lines(): array
    {
        $lines = [];
        for ($b = 0; $b < 256; $b++) {
            $c = chr($b);
            foreach (['', ' ', '  ', "\t"] as $lead) {
                foreach (['', 'x', '{.k} x', ':: t', '`` a', '| b |', ' ]: /u'] as $tail) {
                    $lines[] = $lead . $c . $tail;
                }
            }
        }
        foreach (
            [
                '``` php', '~~~', '````', ':::', '::: note', '::: |', ':::: [l]',
                '| a | b |', '|a|', '+ b |', '  + c |', '[r]: /u', '[^f]: note',
                '[a]: /u {.c}', '{.k}', '{#i}', '{}', '{.a}{.b}', '', ' ', "\t",
                '   ', "  \t ", 'x', '- x', '> q', '|a|{.r}', '+ b | ',
            ] as $line
        ) {
            $lines[] = $line;
        }

        return $lines;
    }

    public function testEveryHeadAcceptsWhereverItsParserDoes(): void
    {
        $fenced = new FencedBlockParser();
        $tables = new TableParser();
        $extractor = new ReferenceDefinitionExtractor();
        $probe = self::probe();

        $accepted = ['code' => 0, 'div' => 0, 'row' => 0, 'continuation' => 0, 'definition' => 0, 'attribute' => 0];
        $refused = [];

        foreach (self::lines() as $line) {
            $shown = var_export($line, true);
            foreach (
                [
                    ['code', $fenced->parseCodeFenceOpener($line) !== null, $fenced->isCodeFenceHead($line, 0)],
                    ['div', $fenced->parseDivFenceOpener($line) !== null, $fenced->isDivFenceHead($line, 0)],
                    ['row', $tables->isTableRow($line), $tables->isTableRowHead($line, 0)],
                    ['continuation', $tables->isContinuationRow($line), $tables->isContinuationRowHead($line, 0)],
                    [
                        'definition',
                        $extractor->matchDefinitionLine($line) !== null,
                        ReferenceDefinitionExtractor::isDefinitionHead($line, 0),
                    ],
                    ['attribute', $probe->payload($line) !== null, $probe->head($line, 0)],
                ] as [$name, $parserAccepts, $headAccepts]
            ) {
                if (!$parserAccepts) {
                    continue;
                }
                $accepted[$name]++;
                if (!$headAccepts) {
                    $refused[] = $name . ' refused ' . $shown;
                }
            }
        }

        $this->assertSame([], $refused, 'a head refused a line its own parser accepts');
        foreach ($accepted as $name => $count) {
            $this->assertGreaterThan(0, $count, sprintf('nothing in the sweep reaches the %s parser', $name));
        }
    }

    /**
     * The two readings this walk moved from `^` to an offset - the comment
     * predicate and the two line-geometry helpers - answer at offset zero
     * exactly what the spelling they replaced answered.
     */
    public function testTheOffsetReadingsAgreeWithTheAnchoredOnesAtZero(): void
    {
        $probe = self::probe();
        $checked = 0;

        foreach (self::lines() as $line) {
            $shown = var_export($line, true);
            $this->assertSame(
                preg_match('/^[ \t]*%%/', $line) === 1,
                $probe->comment($line, 0),
                'the comment reading moved for ' . $shown,
            );
            $this->assertSame(
                strspn($line, " \t") === strlen($line),
                IndentationHelper::isBlankFrom($line, 0),
                'the blank reading moved for ' . $shown,
            );
            $this->assertSame(
                rtrim($line, " \t"),
                substr($line, 0, IndentationHelper::trimmedEnd($line)),
                'the trimmed end moved for ' . $shown,
            );
            $checked++;
        }

        $this->assertGreaterThan(7000, $checked);
    }

    /**
     * Every head reads a SUFFIX as it reads the line that suffix was cut from.
     * Cutting is exactly what the walk refuses to do, so nothing else proves
     * the offset argument is being honoured rather than ignored.
     */
    public function testEveryHeadReadsASuffixAsItReadsTheWholeLine(): void
    {
        $fenced = new FencedBlockParser();
        $tables = new TableParser();
        $probe = self::probe();
        $prefixes = ['', '> ', '- ', '> - ', '- > ', '  ', "\t", '1. ', '> > ', '-{.k} '];
        $rests = [
            '``` php', '~~~ x', ':::', '::: note', '| a | b |', '+ b |', '[r]: /u',
            '[^f]: n', '{.k}', '%% c', '  %% c', 'x', '', ' ', '#', '#{}', '%%',
        ];

        foreach ($prefixes as $prefix) {
            foreach ($rests as $tail) {
                $line = $prefix . $tail;
                $at = strlen($prefix);
                $rest = substr($line, $at);
                $shown = var_export($line, true) . ' at ' . $at;

                $this->assertSame($fenced->isCodeFenceHead($rest, 0), $fenced->isCodeFenceHead($line, $at), $shown);
                $this->assertSame($fenced->isDivFenceHead($rest, 0), $fenced->isDivFenceHead($line, $at), $shown);
                $this->assertSame($tables->isTableRowHead($rest, 0), $tables->isTableRowHead($line, $at), $shown);
                $this->assertSame(
                    $tables->isContinuationRowHead($rest, 0),
                    $tables->isContinuationRowHead($line, $at),
                    $shown,
                );
                $this->assertSame(
                    ReferenceDefinitionExtractor::isDefinitionHead($rest, 0),
                    ReferenceDefinitionExtractor::isDefinitionHead($line, $at),
                    $shown,
                );
                $this->assertSame($probe->head($rest, 0), $probe->head($line, $at), $shown);
                $this->assertSame($probe->comment($rest, 0), $probe->comment($line, $at), $shown);
                $this->assertSame(
                    IndentationHelper::isBlankFrom($rest, 0),
                    IndentationHelper::isBlankFrom($line, $at),
                    $shown,
                );
            }
        }
    }

    /**
     * The quote rule reads its marker off the TRIMMED line without building it:
     * asking with the trimmed end must answer what the copying spelling did.
     */
    public function testTheQuoteWidthReadsTheTrimmedLineWithoutBuildingIt(): void
    {
        $lines = self::lines();
        $lines[] = '> ';
        $lines[] = '>  ';
        $lines[] = ">\t";
        $lines[] = '> >  ';
        $lines[] = '>   ';
        $seen = 0;

        foreach ($lines as $line) {
            $trimmed = rtrim($line, " \t");
            $end = IndentationHelper::trimmedEnd($line);
            $length = strlen($line);
            for ($at = 0; $at <= $length; $at++) {
                $expected = ContainerPrefix::quoteContent(substr($trimmed, min($at, strlen($trimmed))));
                $width = ContainerPrefix::quoteMarkerWidth($line, $at, $end);
                if ($at > strlen($trimmed)) {
                    // Past the trimmed line entirely: there is no marker there.
                    $this->assertNull($width, var_export($line, true) . ' at ' . $at);

                    continue;
                }
                $this->assertSame(
                    $expected === null ? null : strlen(substr($trimmed, $at)) - strlen($expected),
                    $width,
                    var_export($line, true) . ' at ' . $at,
                );
                $seen++;
            }
        }

        $this->assertGreaterThan(7000, $seen);
    }

    private static function probe(): object
    {
        return new class extends BlockParser {
            public function head(string $line, int $at): bool
            {
                return self::isBlockAttributeHead($line, $at);
            }

            public function payload(string $line): ?string
            {
                return $this->parseSingleLineBlockAttributePayload($line);
            }

            public function comment(string $line, int $at): bool
            {
                return $this->isCommentLineOrFence($line, $at);
            }
        };
    }
}
