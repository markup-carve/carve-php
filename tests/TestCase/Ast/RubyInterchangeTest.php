<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Filter\ProfileFilter;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

final class RubyInterchangeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'ruby',
                            'pairs' => [
                                ['base' => [['type' => 'text', 'value' => 'x']], 'annotation' => [['type' => 'text', 'value' => 'a']]],
                                ['base' => [['type' => 'text', 'value' => 'y']], 'annotation' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testPairsRoundTripAndSurviveClone(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode(self::payload());
        $ruby = $document->getChildren()[0]->getChildren()[0];

        self::assertInstanceOf(Ruby::class, $ruby);
        self::assertCount(3, $ruby->getChildren());
        self::assertSame(self::payload(), $codec->encode($document));
        self::assertSame(self::payload(), $codec->encode(clone $document));
    }

    public function testReplacingTraversedChildUpdatesItsPair(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $ruby = $document->getChildren()[0]->getChildren()[0];
        self::assertInstanceOf(Ruby::class, $ruby);
        $base = $ruby->getPairs()[0]['base'][0];

        self::assertTrue($ruby->replaceChildWithMany($base, [new Text('changed')]));
        self::assertSame('changed', (new AstCodec())->encode($document)['children'][0]['children'][0]['pairs'][0]['base'][0]['value']);
    }

    public function testEmptyPairsAreRejected(): void
    {
        $payload = self::payload();
        $payload['children'][0]['children'][0]['pairs'] = [];

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testPairRequiresAClosedBaseAndAnnotation(): void
    {
        foreach (['base' => [], 'annotation' => null, 'extra' => 'x'] as $field => $value) {
            $payload = self::payload();
            if ($field === 'annotation') {
                unset($payload['children'][0]['children'][0]['pairs'][0]['annotation']);
            } else {
                $payload['children'][0]['children'][0]['pairs'][0][$field] = $value;
            }
            try {
                (new AstCodec())->decode($payload);
                self::fail($field . ' was accepted');
            } catch (AstDecodeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNativeAndFlattenedRenderers(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $native = '<ruby>x<rp>(</rp><rt>a</rt><rp>)</rp>y<rp>(</rp><rt></rt><rp>)</rp></ruby>';

        self::assertSame('<p>' . $native . "</p>\n", (new HtmlRenderer())->render($document));
        self::assertSame($native . "\n", (new MarkdownRenderer())->render($document));
        self::assertSame("x(a)y()\n", (new PlainTextRenderer())->render($document));
        self::assertSame("x(a)y()\n", (new AnsiRenderer())->render($document));
        self::assertSame("x(a)y()\n", (new CarveRenderer())->render($document));
    }

    public function testFlatteningReportsOneLossPerNode(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $renderer = new PlainTextRenderer();
        $renderer->beginRenderLossCollection('plain', 0);
        $renderer->render($document);
        $report = $renderer->finishRenderLossCollection();

        self::assertSame(1, $report['totalLosses']);
        self::assertSame([], $report['losses']);
        self::assertTrue($report['truncated']);
    }

    public function testNestedRubyReportsEachNodeOnceInCarve(): void
    {
        $payload = self::payload();
        $inner = $payload['children'][0]['children'][0];
        $payload['children'][0]['children'][0]['pairs'][0]['base'] = [$inner];
        $document = (new AstCodec())->decode($payload);
        $renderer = new CarveRenderer();
        $renderer->beginRenderLossCollection('carve', 10);
        $renderer->render($document);
        $report = $renderer->finishRenderLossCollection();

        self::assertSame(2, $report['totalLosses']);
    }

    public function testMarkdownRubyUsesItsOwnEscapingAndLossCollector(): void
    {
        $payload = self::payload();
        $payload['children'][0]['children'][0]['pairs'][0]['base'][0]['value'] = '*a*';
        $payload['children'][0]['children'][0]['pairs'][0]['annotation'] = [
            ['type' => 'raw_inline', 'format' => 'latex', 'content' => 'lost'],
        ];
        $document = (new AstCodec())->decode($payload);
        $renderer = new MarkdownRenderer();
        $renderer->beginRenderLossCollection('markdown', 10);
        $output = $renderer->render($document);
        $report = $renderer->finishRenderLossCollection();

        self::assertStringContainsString('\\*a\\*', $output);
        self::assertSame(1, $report['totalLosses']);
        self::assertSame('raw-format-dropped', $report['losses'][0]['code']);
    }

    public function testProfileCanFlattenRubyWithoutLosingAnnotations(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $filter = new ProfileFilter();
        $filter->filter($document, Profile::full()->denyInline(['ruby'])->onDisallowed(Profile::ACTION_TO_TEXT));

        self::assertSame("x(a)y()\n", (new PlainTextRenderer())->render($document));
        self::assertSame('text', (new AstCodec())->encode($document)['children'][0]['children'][0]['type']);
    }

    public function testProfileStrippingBaseMarkupKeepsAnnotation(): void
    {
        $payload = self::payload();
        $payload['children'][0]['children'][0]['pairs'][0]['base'] = [
            [
                'type' => 'strong',
                'children' => [['type' => 'text', 'value' => 'x']],
            ],
        ];
        $document = (new AstCodec())->decode($payload);
        $filter = new ProfileFilter();
        $filter->filter($document, Profile::full()->denyInline(['strong'])->onDisallowed(Profile::ACTION_STRIP));
        $pairs = (new AstCodec())->encode($document)['children'][0]['children'][0]['pairs'];

        self::assertSame('a', $pairs[0]['annotation'][0]['value']);
        self::assertSame('', $pairs[0]['base'][0]['value']);
        self::assertNotEmpty($filter->getViolations());
    }

    public function testRubyPairArraysCountTowardDecodeDepth(): void
    {
        $inline = ['type' => 'text', 'value' => 'x'];
        for ($depth = 0; $depth < 450; $depth++) {
            $inline = ['type' => 'ruby', 'pairs' => [['base' => [$inline], 'annotation' => []]]];
        }
        $payload = [

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [$inline]],
            ],
        ];

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('nests deeper');
        (new AstCodec())->decode($payload);
    }
}
