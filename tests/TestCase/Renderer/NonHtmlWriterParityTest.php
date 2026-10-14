<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class NonHtmlWriterParityTest extends TestCase
{
    public function testCoreCorpusWriterCasesMatchReferenceOutputs(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../../fixtures/non-html-writer-parity.json');
        self::assertIsString($fixture);
        $cases = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            $source = $case['source'] ?? file_get_contents(__DIR__ . '/../../spec/tests/corpus/' . $case['name'] . '.crv');
            self::assertIsString($source);
            $document = (new CarveConverter())->parse($source);
            $renderer = match ($case['target']) {
                'markdown' => new MarkdownRenderer(),
                'plain' => new PlainTextRenderer(),
                'ansi' => new AnsiRenderer(),
            };
            self::assertSame($case['expected'], $renderer->render($document), $case['name'] . ' ' . $case['target']);
        }
    }
}
