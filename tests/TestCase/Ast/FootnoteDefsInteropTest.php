<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\TestCase;

/**
 * Older carve-js and carve-php payloads kept footnote DEFINITIONS in a
 * root-level `footnoteDefs` map rather than as block nodes in `children`.
 *
 * The canonical PART 12 §7 shape is the tree form and it is now the ONLY one an
 * ingest accepts (carve-php#1002): the root map is refused, and a stored payload
 * carrying it is converted once by `StoredPayloadUpgrade` - which is what these
 * tests exercise, because the definitions still have to survive the trip.
 */
class FootnoteDefsInteropTest extends TestCase
{
    /**
     * A carve-js shaped payload: definitions in a root map, not in `children`.
     *
     * @return array<string, mixed>
     */
    private function jsShaped(): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        ['type' => 'footnote_ref', 'id' => 'r'],
                    ],
                ],
            ],
            'footnoteDefs' => [
                'r' => [
                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'def']]],
                ],
            ],
            'srcByteLength' => 0,
        ];
    }

    public function testTheRootMapIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('a root `footnoteDefs` map');

        (new AstCodec())->decode($this->jsShaped());
    }

    public function testItDecodesOnceItHasBeenUpgraded(): void
    {
        $document = (new AstCodec())->decode(StoredPayloadUpgrade::upgrade($this->jsShaped()));

        $this->assertNotSame([], $document->getChildren());
    }

    public function testItRendersIdenticallyToTheEquivalentSource(): void
    {
        $decoded = (new AstCodec())->decode(StoredPayloadUpgrade::upgrade($this->jsShaped()));

        $this->assertSame(
            (new CarveConverter())->convert("a[^r]\n\n[^r]: def\n"),
            (new CarveConverter())->render($decoded),
        );
    }

    public function testTheReferenceResolvesAgainstTheUpgradedDefinition(): void
    {
        // Not merely present: the definition has to be found, or the reference
        // renders literally as `[^r]` instead of as a footnote marker.
        $decoded = (new AstCodec())->decode(StoredPayloadUpgrade::upgrade($this->jsShaped()));
        $html = (new CarveConverter())->render($decoded);

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('[^r]', $html);
    }

    /**
     * The upgrade is IDEMPOTENT, because a migration nobody can re-run safely
     * is a migration that has to be tracked by hand.
     */
    public function testUpgradingAnAlreadyUpgradedPayloadChangesNothing(): void
    {
        $once = StoredPayloadUpgrade::upgrade($this->jsShaped());

        $this->assertSame($once, StoredPayloadUpgrade::upgrade($once));
    }

    public function testThisEnginesOwnPayloadIsUnaffected(): void
    {
        $codec = new AstCodec();
        $source = "a[^r]\n\n[^r]: def\n";
        $decoded = $codec->decode($codec->encode((new CarveConverter())->parse($source)));

        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->render($decoded),
        );
    }
}
