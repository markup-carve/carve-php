<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class RawPayloadTerminationTest extends TestCase
{
    public function testSharedAstVectorsKeepTerminationAndDecodeLiteralCode(): void
    {
        $codec = new AstCodec();
        $vectors = json_decode((string)file_get_contents(__DIR__ . '/fixtures/raw-payload-termination.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            $wire = ['type' => 'document', 'srcByteLength' => 0, 'children' => [$vector['raw']]];
            $doc = $codec->decode($wire);
            self::assertEquals($wire, $codec->encode($doc), $vector['id']);
            $raw = $doc->getChildren()[0];
            self::assertInstanceOf(RawBlock::class, $raw);
            self::assertSame($vector['codeContent'], $raw->getCodeBlockContent(), $vector['id']);
        }
    }

    public function testSourceEofMatchesLiteralCodeInSafeAndProfileModes(): void
    {
        foreach (['', "\n", 'x', "x\n", "x\n\n"] as $payload) {
            foreach ([new CarveConverter(safeMode: true), new CarveConverter(profile: Profile::article())] as $converter) {
                self::assertSame($converter->convert("```html\n" . $payload), $converter->convert("```=html\n" . $payload));
            }
            $doc = (new CarveConverter())->parse("```=html\n" . $payload);
            $raw = $doc->getChildren()[0];
            self::assertInstanceOf(RawBlock::class, $raw);
            self::assertSame($payload !== '' && !str_ends_with($payload, "\n") ? false : null, $raw->getContentTerminated());
        }
    }

    public function testSharedSourceHosts(): void
    {
        $fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures/raw-payload-source-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            foreach ([new CarveConverter(safeMode: true), new CarveConverter(profile: Profile::article())] as $converter) {
                self::assertSame($converter->convert($fixture['codeSource']), $converter->convert($fixture['source']), $fixture['id']);
            }
            $wire = (new AstCodec())->encodeJson((new CarveConverter())->parse($fixture['source']));
            self::assertSame($fixture['contentTerminated'] === false, str_contains($wire, '"contentTerminated":false'), $fixture['id']);
        }
    }

    public function testFormatterReportsChangedTermination(): void
    {
        $renderer = new CarveRenderer();
        $renderer->beginConversionDiagnosticCollection();
        $renderer->render((new CarveConverter())->parse("```=html\nx"));
        $report = $renderer->finishConversionDiagnosticCollection();
        self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('contentTerminated', $report['diagnostics'][0]['field']);
    }

    public function testProseMirrorKeepsTermination(): void
    {
        $doc = (new CarveConverter())->parse("```=html\nx");
        $pm = (new ProseMirrorRenderer())->render($doc);
        $back = (new ProseMirrorToCarve())->convert($pm);
        $raw = $back->getChildren()[0];
        self::assertInstanceOf(RawBlock::class, $raw);
        self::assertSame(false, $raw->getContentTerminated());
        self::assertSame('x', $raw->getCodeBlockContent());
    }
}
