<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §33 (CARVE-P12-055): a block an extension owns, carried with a core
 * fallback a reader that does not implement the extension renders instead.
 */
final class ABlockExtensionDeclaresItsFallbackTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function payload(array $extra = []): array
    {
        $node = [
            'type' => 'block_extension',
            'name' => 'org.example.diagram',
            'fallback' => ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'A flow chart.']]],
        ] + $extra;

        return ['type' => 'document', 'srcByteLength' => 0, 'children' => [$node]];
    }

    public function testItDecodesAndRoundTrips(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode(self::payload());
        $node = $document->getChildren()[0];

        self::assertInstanceOf(BlockExtension::class, $node);
        self::assertSame('org.example.diagram', $node->getName());
        self::assertInstanceOf(Paragraph::class, $node->getFallback());
        self::assertSame(self::payload(), $codec->encode($document));
        self::assertSame(self::payload(), $codec->encode(clone $document));
    }

    public function testCarveReportsTheFallbackSubstitution(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertSame('A flow chart.', trim($writer->render($document)));
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame(1, $report['totalDiagnostics']);
        self::assertSame('structure-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('block_extension', $report['diagnostics'][0]['node']);
    }

    public function testTheFallbackIsAlsoTheNodesChildSoEveryWalkReachesIt(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $node = $document->getChildren()[0];

        self::assertInstanceOf(BlockExtension::class, $node);
        self::assertSame([$node->getFallback()], $node->getChildren());
    }

    public function testTheVersionAndPayloadRoundTrip(): void
    {
        $codec = new AstCodec();
        $payload = self::payload([
            'version' => '2',
            'payload' => ['format' => 'application/vnd.example.diagram+json', 'value' => ['nodes' => 2]],
        ]);

        self::assertSame($payload, $codec->encode($codec->decode($payload)));
    }

    /**
     * The payload is NOT Carve content, and the codec's reflection walk builds a
     * node out of any nested array carrying a `type` key. A `type` inside an
     * opaque diagram spec used to reach `decodeNode()` and be refused as an
     * unknown node type.
     */
    public function testAPayloadValueCarryingATypeKeyStaysData(): void
    {
        $codec = new AstCodec();
        $payload = self::payload([
            'payload' => ['format' => 'application/json', 'value' => ['type' => 'swimlane', 'lanes' => 3]],
        ]);
        $document = $codec->decode($payload);
        $node = $document->getChildren()[0];

        self::assertInstanceOf(BlockExtension::class, $node);
        self::assertSame(['type' => 'swimlane', 'lanes' => 3], $node->getPayload()['value'] ?? null);
        self::assertSame($payload, $codec->encode($document));
    }

    public function testAMissingFallbackIsRefused(): void
    {
        $payload = self::payload();
        unset($payload['children'][0]['fallback']);

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $payload = self::payload();
        $payload['children'][0]['name'] = '';

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testAPayloadWithoutAFormatIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode(self::payload(['payload' => ['value' => 1]]));
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function targetProvider(): array
    {
        return [
            'html' => [HtmlRenderer::class, '<p>A flow chart.</p>'],
            'markdown' => [MarkdownRenderer::class, 'A flow chart.'],
            'plain text' => [PlainTextRenderer::class, 'A flow chart.'],
            'ansi' => [AnsiRenderer::class, 'A flow chart.'],
            'carve' => [CarveRenderer::class, 'A flow chart.'],
        ];
    }

    /**
     * @param class-string $renderer
     * @param string $expected
     */
    #[DataProvider('targetProvider')]
    public function testEveryTargetRendersTheFallback(string $renderer, string $expected): void
    {
        $document = (new AstCodec())->decode(self::payload([
            'version' => '2',
            'payload' => ['format' => 'application/json', 'value' => ['nodes' => 2]],
        ]));
        /** @var \MarkupCarve\Carve\Renderer\RendererInterface $instance */
        $instance = new $renderer();

        self::assertSame($expected, trim($instance->render($document)));
    }

    public function testTheProseMirrorBridgeKeepsTheFallbackAsContent(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $renderer = new ProseMirrorRenderer();
        $editor = $renderer->render($document);

        self::assertSame('carveBlockExtension', $editor['content'][0]['type']);
        self::assertSame('paragraph', $editor['content'][0]['content'][0]['type']);
        self::assertSame([], $renderer->degradedTypes());
    }
}
