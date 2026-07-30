<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Stamp;
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

    public function testReadReturnsNullForAnUnstampedDocument(): void
    {
        // Hand-written documents are the normal case for this.
        $this->assertNull(Stamp::read("# Title\n\ntext\n"));
        $this->assertNull(Stamp::read(''));
    }

    public function testReadRecognizesTheLineForm(): void
    {
        $stamp = Stamp::read("text\n\n%% carve-version: 0.1; generated-by: carve-php 0.1.0\n");

        $this->assertSame(['version' => '0.1', 'generatedBy' => 'carve-php 0.1.0'], $stamp);
    }

    public function testReadRecognizesTheBlockForm(): void
    {
        $source = "text\n\n%%%\ncarve-version: 0.0.9\ngenerated-by: carve-js 0.0.9\n%%%\n";

        $this->assertSame(['version' => '0.0.9', 'generatedBy' => 'carve-js 0.0.9'], Stamp::read($source));
    }

    public function testReadIgnoresAnUnrelatedTrailingComment(): void
    {
        // The marker is identified by carve-version: as its first field, so an
        // ordinary trailing comment must not be read as provenance.
        $this->assertNull(Stamp::read("text\n\n%% just a note\n"));
        $this->assertNull(Stamp::read("text\n\n%%%\njust a note\n%%%\n"));
    }

    public function testReadToleratesAMissingGeneratedBy(): void
    {
        $stamp = Stamp::read("text\n\n%% carve-version: 0.1\n");

        $this->assertSame(['version' => '0.1', 'generatedBy' => null], $stamp);
    }

    public function testWhatStampCarveWritesIsWhatReadReturns(): void
    {
        // The pair has to agree, or the upgrade procedure reads the wrong version.
        foreach (['line', 'block'] as $form) {
            $stamped = Stamp::stampCarve("text\n", self::GENERATED_BY, $form);
            $stamp = Stamp::read($stamped);

            $this->assertNotNull($stamp, sprintf('%s form must be readable', $form));
            $this->assertSame(CarveConverter::SPEC_VERSION, $stamp['version']);
            $this->assertSame(self::GENERATED_BY, $stamp['generatedBy']);
        }
    }

    public function testNeedsReviewComparesAgainstTheTargetedSpecVersion(): void
    {
        $current = "text\n\n%% carve-version: " . CarveConverter::SPEC_VERSION . "; generated-by: x\n";

        $this->assertFalse(Stamp::needsReview($current));
        $this->assertTrue(Stamp::needsReview("text\n\n%% carve-version: 0.0.9; generated-by: x\n"));

        // Unknown provenance answers true: assuming a document is current is the
        // unsafe direction.
        $this->assertTrue(Stamp::needsReview("text\n"));

        // A document from a future version does not need review by this engine.
        $this->assertFalse(Stamp::needsReview("text\n\n%% carve-version: 99.0; generated-by: x\n"));
    }
}
