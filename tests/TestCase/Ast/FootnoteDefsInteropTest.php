<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Older carve-js and carve-php payloads kept footnote DEFINITIONS in a
 * root-level `footnoteDefs` map rather than as block nodes in `children`. The
 * canonical PART 12 §7 shape is now the tree form, but stored root-form payloads
 * still have to decode.
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

    public function testItDecodesInsteadOfBeingRefused(): void
    {
        $document = (new AstCodec())->decode($this->jsShaped());

        $this->assertNotSame([], $document->getChildren());
    }

    public function testItRendersIdenticallyToTheEquivalentSource(): void
    {
        $decoded = (new AstCodec())->decode($this->jsShaped());

        $this->assertSame(
            (new CarveConverter())->convert("a[^r]\n\n[^r]: def\n"),
            (new CarveConverter())->render($decoded),
        );
    }

    public function testTheReferenceResolvesAgainstTheAdoptedDefinition(): void
    {
        // Not merely present: the definition has to be found, or the reference
        // renders literally as `[^r]` instead of as a footnote marker.
        $decoded = (new AstCodec())->decode($this->jsShaped());
        $html = (new CarveConverter())->render($decoded);

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('[^r]', $html);
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
