<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each destination the PART 9 section 25 sink denylist blanks owes one
 * `CARVE-P2-024` row with the code `destination-denied`, the selected target,
 * `nodeType` of `inline`, its `pos` when recorded, a short message and NO
 * `format` (markup-carve/carve#2679).
 *
 * THE EMITTED VALUE DOES NOT MOVE. Every assertion on a report here is paired
 * with the unchecked render of the same source, so a row that only appeared
 * because the output changed would fail.
 */
class ABlankedDestinationOwesOneRenderLossRowTest extends TestCase
{
    /**
     * Corpus 536: one denied link and one denied image in one document, so the
     * single-sink case cannot stand in for the two-row case.
     *
     * @var string
     */
    private const TWO_SINKS = "[report me](javascript:one) and ![report me too](vbscript:two)\n";

    public function testOneDeniedLinkTakesOneRow(): void
    {
        $converter = CarveConverter::create();
        $result = $converter->convertWithReport("[a](javascript:one)\n");

        self::assertSame($converter->convert("[a](javascript:one)\n"), $result->value);
        self::assertSame(1, $result->totalLosses);
        self::assertCount(1, $result->losses);
        self::assertSame('destination-denied', $result->losses[0]['code']);
        self::assertSame('html', $result->losses[0]['target']);
        self::assertSame('inline', $result->losses[0]['nodeType']);
        self::assertArrayNotHasKey('format', $result->losses[0]);
        self::assertNotSame('', $result->losses[0]['message']);
        self::assertSame(1, $result->losses[0]['pos']['startLine']);
        self::assertSame(['destination-denied' => 1], $result->lossCounts);
    }

    public function testTheThreeSinksEachTakeTheirOwnRow(): void
    {
        $result = CarveConverter::create()
            ->convertWithReport("[a](javascript:one)\n\n![b](vbscript:two)\n\n<data:text/html,three>\n");

        self::assertSame(3, $result->totalLosses);
        self::assertSame(
            ['destination-denied', 'destination-denied', 'destination-denied'],
            array_column($result->losses, 'code'),
        );
        self::assertSame([1, 3, 5], array_column(array_column($result->losses, 'pos'), 'startLine'));
    }

    public function testCorpus536OwesTwoRowsFromOneRender(): void
    {
        $result = CarveConverter::create()->convertWithReport(self::TWO_SINKS);

        self::assertSame(
            '<p><a href="">report me</a> and <img src="" alt="report me too"></p>' . "\n",
            $result->value,
        );
        self::assertSame(2, $result->totalLosses);
        self::assertSame(['destination-denied' => 2], $result->lossCounts);
        self::assertFalse($result->truncated);
        self::assertSame([1, 33], array_column(array_column($result->losses, 'pos'), 'startColumn'));
    }

    /**
     * Safe mode gates neither the hardening nor the reporting, so both modes owe
     * the same two rows and emit the same value.
     *
     * @return array<string, array{0: \MarkupCarve\Carve\SafeMode|bool|null}>
     */
    public static function safeModes(): array
    {
        return [
            'baseline hardening only' => [null],
            'safe mode on' => [SafeMode::defaults()],
        ];
    }

    #[DataProvider('safeModes')]
    public function testBothSafeModesOweTheSameRows(SafeMode|bool|null $safeMode): void
    {
        $converter = CarveConverter::create();
        $converter->setSafeMode($safeMode);
        $result = $converter->convertWithReport(self::TWO_SINKS);

        self::assertSame(
            '<p><a href="">report me</a> and <img src="" alt="report me too"></p>' . "\n",
            $result->value,
        );
        self::assertSame(2, $result->totalLosses);
        self::assertSame(['destination-denied' => 2], $result->lossCounts);
    }

    /**
     * The control that separates "reports denials" from "reports everything".
     *
     * @return array<string, array{0: string}>
     */
    public static function allowedSources(): array
    {
        return [
            'https link and image' => ["[ok](https://example.com) and ![ok](https://example.com/a.png)\n"],
            'mailto autolink' => ["<mailto:a@example.com>\n"],
            'relative destination' => ["[ok](./page.crv)\n"],
            'fragment destination' => ["# H\n\n[ok](#h)\n"],
        ];
    }

    #[DataProvider('allowedSources')]
    public function testAnAllowedDestinationOwesNoRow(string $source): void
    {
        foreach (['create', 'markdown', 'ansi'] as $factory) {
            $result = CarveConverter::$factory()->convertWithReport($source);

            self::assertSame(0, $result->totalLosses, $factory . ' reported an allowed destination');
            self::assertSame([], $result->losses, $factory);
        }
    }

    /**
     * A target reports what IT blanks. Markdown resolves its destinations one
     * step removed and blanks both sinks; ANSI prints a link's target beside the
     * text but never an image source or an autolink's own URL, so it owes one
     * row here; plain text and the canonical Carve writer emit no destination to
     * blank and owe none.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function targetRowCounts(): array
    {
        return [
            'html' => ['create', 2],
            'markdown' => ['markdown', 2],
            'ansi' => ['ansi', 1],
            'plain' => ['plainText', 0],
            'carve' => ['carve', 0],
        ];
    }

    #[DataProvider('targetRowCounts')]
    public function testEachTargetReportsWhatItBlanks(string $factory, int $rows): void
    {
        $converter = CarveConverter::$factory();
        $result = $converter->convertWithReport(self::TWO_SINKS);

        self::assertSame($converter->convert(self::TWO_SINKS), $result->value);
        self::assertSame($rows, $result->totalLosses);
        foreach ($result->losses as $loss) {
            self::assertSame('destination-denied', $loss['code']);
            self::assertSame('inline', $loss['nodeType']);
            self::assertArrayNotHasKey('format', $loss);
        }
    }

    /**
     * `--allow-loss` names an exception per code and this code is deliberately
     * not one of them (markup-carve/carve#2684 is the open question), so a
     * strict checked render still fails on a blanked destination.
     */
    public function testAStrictCheckedRenderFailsOnABlankedDestination(): void
    {
        $this->expectException(RenderLossException::class);
        CarveConverter::create()->convertWithReport(self::TWO_SINKS, strictLosses: true);
    }
}
