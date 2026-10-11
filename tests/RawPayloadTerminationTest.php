<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class RawPayloadTerminationTest extends TestCase
{
    public function testSharedAstVectorsKeepLiteralContent(): void
    {
        $codec = new AstCodec();
        $vectors = json_decode((string)file_get_contents(__DIR__ . '/fixtures/raw-payload-termination.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            $wire = ['type' => 'document', 'srcByteLength' => 0, 'children' => [$vector['raw']]];
            $doc = $codec->decode($wire);
            self::assertEquals($wire, $codec->encode($doc), $vector['id']);
            $raw = $doc->getChildren()[0];
            self::assertInstanceOf(RawBlock::class, $raw);
            self::assertSame($vector['codeContent'], $raw->getContent(), $vector['id']);
            self::assertSame($vector['rawPassthrough'] . "\n", (new CarveConverter())->render($doc), $vector['id']);
            $code = clone $doc;
            $code->removeChild($code->getChildren()[0]);
            $code->appendChild(new CodeBlock($vector['codeContent'], 'html'));
            self::assertSame((new CarveConverter(safeMode: true))->render($code), (new CarveConverter(safeMode: true))->render($doc), $vector['id']);
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
            self::assertSame($payload, $raw->getContent());
        }
    }

    public function testEveryRawFormatRetainsExactEofPayload(): void
    {
        foreach (['html', 'latex', 'markdown'] as $format) {
            foreach (['', "\n", "\n\n", 'x', "x\n", "x\n\n", " \t", "\u{00a0}"] as $payload) {
                $converter = new CarveConverter();
                $raw = $converter->parse('```=' . $format . "\n" . $payload)->getChildren()[0];
                $code = $converter->parse('```' . $format . "\n" . $payload)->getChildren()[0];
                self::assertInstanceOf(RawBlock::class, $raw);
                self::assertInstanceOf(CodeBlock::class, $code);
                self::assertSame($payload, $raw->getContent());
                self::assertSame($code->getContent(), $raw->getContent());
            }
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
            self::assertStringNotContainsString('contentTerminated', $wire, $fixture['id']);
        }
    }

    public function testMarkerOnlyEofKeepsLiteralPayloadSemantics(): void
    {
        $expected = [
            'marker-only-eof-0' => "<blockquote>\n  \n</blockquote>\n",
            'marker-only-eof-1' => "<blockquote>\n  \n\n</blockquote>\n",
            'marker-only-eof-2' => "<blockquote>\n  x\n\n</blockquote>\n",
            'marker-only-eof-3' => "<ul>\n  <li>\n    \n\n  </li>\n</ul>\n",
        ];
        $fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures/raw-payload-source-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            if (isset($expected[$fixture['id']])) {
                self::assertSame($expected[$fixture['id']], (new CarveConverter())->convert($fixture['source']), $fixture['id']);
            }
        }
    }

    public function testFormatterReportsChangedTermination(): void
    {
        $renderer = new CarveRenderer();
        $renderer->beginConversionDiagnosticCollection();
        $renderer->render((new CarveConverter())->parse("```=html\nx"));
        $report = $renderer->finishConversionDiagnosticCollection();
        self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('content', $report['diagnostics'][0]['field']);
    }

    public function testProseMirrorKeepsLiteralContent(): void
    {
        $doc = (new CarveConverter())->parse("```=html\nx");
        $pm = (new ProseMirrorRenderer())->render($doc);
        $back = (new ProseMirrorToCarve())->convert($pm);
        $raw = $back->getChildren()[0];
        self::assertInstanceOf(RawBlock::class, $raw);
        self::assertSame('x', $raw->getContent());
    }

    public function testProposedTerminationFlagIsRejected(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'raw_block',
                    'format' => 'html',
                    'content' => 'x',
                    'contentTerminated' => false,
                ],
            ],
        ]);
    }
}
