<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Stamp;
use PHPUnit\Framework\TestCase;

class StampTest extends TestCase
{
    /**
     * @var string
     */
    private const GENERATED_BY = 'carve-php 0.1.0';

    /**
     * @var string
     */
    private const LINE_MARKER = '%% carve-version: 0.1; generated-by: carve-php 0.1.0';

    /**
     * @var string
     */
    private const BLOCK_MARKER = "%%%\ncarve-version: 0.1\ngenerated-by: carve-php 0.1.0\n%%%";

    public function testOneLinerDefaultFormOutput(): void
    {
        $this->assertSame(
            "a\n\n" . self::LINE_MARKER . "\n",
            Stamp::stampCarve("a\n", self::GENERATED_BY),
        );
    }

    public function testBlockFormOutput(): void
    {
        $this->assertSame(
            "a\n\n" . self::BLOCK_MARKER . "\n",
            Stamp::stampCarve("a\n", self::GENERATED_BY, 'block'),
        );
    }

    public function testStampingIsIdempotent(): void
    {
        $stamped = Stamp::stampCarve("a\n", self::GENERATED_BY);

        $this->assertSame($stamped, Stamp::stampCarve($stamped, self::GENERATED_BY));
    }

    public function testRestampReplacesOtherForm(): void
    {
        $line = Stamp::stampCarve("a\n", self::GENERATED_BY);
        $block = Stamp::stampCarve($line, self::GENERATED_BY, 'block');

        $this->assertSame("a\n\n" . self::BLOCK_MARKER . "\n", $block);
        $this->assertSame($line, Stamp::stampCarve($block, self::GENERATED_BY));
    }

    public function testMarkerRendersNothing(): void
    {
        $converter = new CarveConverter();
        $unstamped = "a\n";
        $stamped = Stamp::stampCarve($unstamped, self::GENERATED_BY);

        $this->assertSame($converter->convert($unstamped), $converter->convert($stamped));
    }

    public function testKeepsUnrelatedTrailingLineComment(): void
    {
        $this->assertSame(
            "a\n\n%% note\n\n" . self::LINE_MARKER . "\n",
            Stamp::stampCarve("a\n\n%% note\n", self::GENERATED_BY),
        );
    }

    public function testEmptyDocumentGetsBareMarker(): void
    {
        $this->assertSame(self::LINE_MARKER . "\n", Stamp::stampCarve('', self::GENERATED_BY));
    }

    public function testPlainToCarvePreservesExistingMarkerByteForByte(): void
    {
        $stamped = "a\n\n" . self::LINE_MARKER . "\n";

        $this->assertSame($stamped, CarveConverter::toCarve($stamped));
    }
}
