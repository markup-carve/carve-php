<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §29 (CARVE-P12-051): a display equation's authored label and the
 * number resolution assigns beside it.
 */
final class MathLabelAndNumberTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     * @param bool $display
     *
     * @return array<string, mixed>
     */
    private static function payload(array $extra = [], bool $display = true): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'math', 'content' => 'a^2 + b^2 = c^2', 'display' => $display] + $extra],
                ],
            ],
        ];
    }

    public function testALabelAndNumberRoundTrip(): void
    {
        $codec = new AstCodec();
        $payload = self::payload(['label' => 'Equation', 'number' => 4]);
        $document = $codec->decode($payload);
        $math = $document->getChildren()[0]->getChildren()[0];

        self::assertInstanceOf(Math::class, $math);
        self::assertSame('Equation', $math->getLabel());
        self::assertSame(4, $math->getNumber());
        self::assertSame($payload, $codec->encode($document));
        self::assertSame($payload, $codec->encode(clone $document));
    }

    public function testALabelWithoutANumberRoundTrips(): void
    {
        $codec = new AstCodec();
        $payload = self::payload(['label' => 'Eq.']);

        self::assertSame($payload, $codec->encode($codec->decode($payload)));
    }

    public function testAnUnlabeledEquationPublishesNeitherField(): void
    {
        $codec = new AstCodec();
        $encoded = $codec->encode($codec->decode(self::payload()));
        $math = $encoded['children'][0]['children'][0];

        self::assertArrayNotHasKey('label', $math);
        self::assertArrayNotHasKey('number', $math);
    }

    public function testANumberWithoutALabelIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode(self::payload(['number' => 4]));
    }

    public function testInlineMathMayNotCarryANumber(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode(self::payload(['label' => 'Eq.', 'number' => 4], display: false));
    }

    public function testALabelOnInlineMathIsRetained(): void
    {
        $codec = new AstCodec();
        $payload = self::payload(['label' => 'Eq.'], display: false);

        self::assertSame($payload, $codec->encode($codec->decode($payload)));
    }

    public function testHtmlIsUnchangedByTheLabel(): void
    {
        $codec = new AstCodec();
        $labeled = (new HtmlRenderer())->render($codec->decode(self::payload(['label' => 'Equation', 'number' => 4])));

        self::assertSame((new HtmlRenderer())->render($codec->decode(self::payload())), $labeled);
    }

    public function testCarveWritesTheFormulaAndLosesTheLabel(): void
    {
        $document = (new AstCodec())->decode(self::payload(['label' => 'Equation', 'number' => 4]));

        self::assertSame('$$`a^2 + b^2 = c^2`', trim((new CarveRenderer())->render($document)));
    }
}
