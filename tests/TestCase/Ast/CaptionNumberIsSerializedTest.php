<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `caption_number.n` reaches the wire.
 *
 * PART 12 §5 names caption numbering as a resolution result that IS serialized,
 * "because recomputing them requires reimplementing PART 9R". Two things kept it
 * off:
 *
 *  1. the number was assigned only on the RENDER path, so the AST saw none - the
 *     codec ran the narrow `resolveCrossReferenceTargets` and not the numbering
 *     pass beside it;
 *  2. the property is `number` here and the reference calls it `n`, and the
 *     schema pins that with `additionalProperties: false` - so publishing
 *     `number` would not merely differ, it would be invalid.
 *
 * Both are fixed, and the pair matters: fixing only the first would have emitted
 * an invalid field (carve-php#843).
 *
 * THE GOLDEN WIRE FIXTURE MOVED BY ONE LINE, deliberately. `AstCodecSchemaTest`
 * exists to make a field rename visible, and its message suggests bumping
 * `AstCodec::VERSION` alongside. That was NOT done: a version bump makes `decode`
 * reject every payload announcing the old number, and no payload this engine ever
 * wrote carried `caption_number.number` - the property was never populated. So
 * nothing that could be read stops being readable.
 */
class CaptionNumberIsSerializedTest extends TestCase
{
    /**
     * A figure whose caption carries the `#` number placeholder.
     *
     * @var string
     */
    protected const NUMBERED_CAPTION = "![a](/p.png)\n^ Figure #: cap\n";

    /**
     * @return array<string, mixed>
     */
    protected function ast(string $source): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse($source));
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    protected function captionNumbers(array $tree): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (is_array($node)) {
                if (($node['type'] ?? null) === 'caption_number') {
                    $found[] = $node;
                }
                foreach ($node as $value) {
                    $walk($value);
                }
            }
        };
        $walk($tree);

        return $found;
    }

    public function testTheNumberIsPublished(): void
    {
        $numbers = $this->captionNumbers($this->ast(self::NUMBERED_CAPTION));

        $this->assertCount(1, $numbers, 'expected one caption number');
        $this->assertSame(1, $numbers[0]['n'] ?? null, (string)json_encode($numbers[0]));
    }

    public function testItIsPublishedAsNNotNumber(): void
    {
        // The schema is `additionalProperties: false` with `n` - `number` is not
        // a cosmetic difference there, it is a field the schema rejects.
        $numbers = $this->captionNumbers($this->ast(self::NUMBERED_CAPTION));

        $this->assertArrayHasKey('n', $numbers[0]);
        $this->assertArrayNotHasKey('number', $numbers[0]);
    }

    public function testASecondFigureNumbersTwo(): void
    {
        // Numbering is per label sequence, so the second figure is 2 - a constant
        // 1 would satisfy the assertions above.
        $source = "![a](/1.png)\n^ Figure #: one\n\n![b](/2.png)\n^ Figure #: two\n";
        $numbers = $this->captionNumbers($this->ast($source));

        $this->assertCount(2, $numbers);
        $this->assertSame(1, $numbers[0]['n'] ?? null);
        $this->assertSame(2, $numbers[1]['n'] ?? null);
    }

    public function testTheNumberSurvivesADecodeAndReEncode(): void
    {
        // A field the encoder writes and the decoder drops is worse than one that
        // was never there: the round trip is what a consumer actually relies on.
        $codec = new AstCodec();
        $once = $this->ast(self::NUMBERED_CAPTION);
        $twice = $codec->encode($codec->decode($once));

        $this->assertSame(
            $this->captionNumbers($once),
            $this->captionNumbers($twice),
            'the caption number did not survive the round trip',
        );
    }

    public function testAnUnnumberedCaptionPublishesNoNumberNode(): void
    {
        // The boundary: `^ cap` with no `#` placeholder has nothing to number, so
        // no caption_number node exists to carry one.
        $numbers = $this->captionNumbers($this->ast("![a](/p.png)\n^ cap\n"));

        $this->assertSame([], $numbers);
    }
}
