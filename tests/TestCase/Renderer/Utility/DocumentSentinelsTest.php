<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer\Utility;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SentinelSpaceExhaustedException;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE PROPERTY: `pick()` never returns a run the document contains.
 *
 * A sentinel exists to be a value the document cannot hold, and the picker used
 * to give that up silently: when the private-use area offered no wide enough
 * run it returned the PREFERRED run, which is a run the document may well
 * contain. That is a check that cannot fail - it always answers, and the wrong
 * answer is invisible, appearing later as text the author never wrote
 * (markup-carve/carve-php#1398).
 *
 * The assertions here are on the PROPERTY rather than on the picked values. A
 * test that checks "a run was picked" passes on the defect.
 */
class DocumentSentinelsTest extends TestCase
{
    /**
     * A document that leaves no gap of `$count` anywhere above `$first`.
     *
     * Every `$count`-th code point is enough: a window of `$count` consecutive
     * candidates then always covers one of them, so no run survives. That is
     * about a sixth of the area for a run of six, which is the density
     * markup-carve/carve-php#1087 measured rather than the whole range an
     * earlier comment claimed was needed.
     */
    private static function exhausting(int $count, int $first): string
    {
        $text = '';
        for ($code = $first; $code <= DocumentSentinels::PRIVATE_USE_LAST; $code += $count) {
            $text .= mb_chr($code, 'UTF-8');
        }

        return $text;
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function runShapeProvider(): array
    {
        return [
            // The canonical writer's run, and the width that moved the boundary
            // when markup-carve/carve-php#1396 widened it from four.
            'six from E001' => [6, 0xE001],
            'five from E001' => [5, 0xE001],
            'four from E001' => [4, 0xE001],
            // The BBCode converter's two stash keys.
            'two from E001' => [2, 0xE001],
            'two from E010' => [2, 0xE010],
        ];
    }

    /**
     * The property, over an ORDINARY document: what comes back is absent.
     */
    #[DataProvider('runShapeProvider')]
    public function testAPickedRunIsNeverPresentInTheText(int $count, int $first): void
    {
        $text = "ordinary text with a stray \u{E005} and a \u{F8FF} in it";
        $run = DocumentSentinels::pick($text, $count, $first);

        $this->assertCount($count, $run);
        foreach ($run as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $text);
        }
    }

    /**
     * The property, over the ADVERSARIAL document: it refuses.
     *
     * Asserted as a refusal rather than as a returned value, because the whole
     * defect was that a value came back at all.
     */
    #[DataProvider('runShapeProvider')]
    public function testAnExhaustedAreaIsRefusedRatherThanCollided(int $count, int $first): void
    {
        $text = self::exhausting($count, $first);

        try {
            $run = DocumentSentinels::pick($text, $count, $first);
        } catch (SentinelSpaceExhaustedException $exception) {
            $this->assertSame($count, $exception->count);
            $this->assertSame($first, $exception->first);

            return;
        }

        // Not reached unless the picker answered. If it ever does, the answer
        // has to satisfy the property - so name which sentinel it broke.
        foreach ($run as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $text,
                'pick() returned a sentinel the document contains',
            );
        }
        $this->fail('pick() answered on an exhausted area instead of refusing');
    }

    /**
     * The CONTROL: one gap is enough, and it is found rather than refused.
     *
     * Without this the refusal above passes on a picker that refuses
     * everything, which would be the same defect pointing the other way.
     */
    public function testASingleSurvivingRunIsStillFound(): void
    {
        $count = 6;
        $first = 0xE001;
        $gapAt = 0xF000;

        $text = '';
        for ($code = $first; $code <= DocumentSentinels::PRIVATE_USE_LAST; $code += $count) {
            if ($code >= $gapAt && $code < $gapAt + ($count * 2)) {
                continue;
            }
            $text .= mb_chr($code, 'UTF-8');
        }

        $run = DocumentSentinels::pick($text, $count, $first);

        $this->assertCount($count, $run);
        foreach ($run as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $text);
        }
    }

    /**
     * THE SURVIVING RUN MAY BE UNALIGNED, and the search has to step by ONE.
     *
     * Stepping by the run WIDTH only ever tests runs aligned to `$first`, and
     * those tile the area without overlap - so a document holding one character
     * per aligned block exhausts that search while unaligned gaps sit between
     * the blocks (markup-carve/carve-php#1087).
     *
     * This document puts one character at the START of every block except one,
     * where it sits at the END. Every ALIGNED window then collides, and the
     * window straddling that pair is free. A by-width search refuses it; a
     * by-one search finds it.
     */
    public function testAnUnalignedSurvivingRunIsFound(): void
    {
        $count = 6;
        $first = 0xE001;
        $shiftedBlock = 1;

        $text = '';
        $block = 0;
        for ($base = $first; $base + $count - 1 <= DocumentSentinels::PRIVATE_USE_LAST; $base += $count) {
            $text .= mb_chr($base + ($block === $shiftedBlock ? $count - 1 : 0), 'UTF-8');
            $block++;
        }

        $run = DocumentSentinels::pick($text, $count, $first);

        $this->assertCount($count, $run);
        foreach ($run as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $text,
                'pick() returned a sentinel the document contains',
            );
        }
    }

    /**
     * An ordinary document still renders, on every target that picks sentinels.
     *
     * The refusal must be reachable only by the adversarial input; a renderer
     * that started throwing on real documents would be a far worse defect than
     * the one being fixed.
     */
    public function testOrdinaryDocumentsStillRenderOnEveryTarget(): void
    {
        $source = "# H\n\n*[AB]: abbrev\n\nThe AB here, with `code` and a\nline break.\n\n| a | b |\n";

        $this->assertStringContainsString('AB', (new CarveConverter())->convert($source));
        $this->assertStringContainsString('AB', CarveConverter::markdown()->convert($source));
        $this->assertStringContainsString('AB', CarveConverter::plainText()->convert($source));
        $this->assertStringContainsString('AB', CarveConverter::ansi()->convert($source));
        $this->assertStringContainsString('AB', CarveConverter::toCarve($source));
    }
}
