<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WriterCorpusParityTest extends TestCase
{
    public function testEmptyDefinitionDestinationKeepsItsMarkdownSlot(): void
    {
        $document = new Document();
        $document->appendChild(new LinkReferenceDefinition('x', ''));

        self::assertSame("[x]: <>\n", (new MarkdownRenderer())->render($document));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        $rows = [
            '313-a-reference-link-s-text-survives-its-own-frame-2' => ['md', 'plain', 'ansi'],
            '313-a-reference-link-s-text-survives-its-own-frame-3' => ['md', 'plain', 'ansi'],
            '579-a-span-never-covers-another-cell' => ['ansi'],
            '579-a-span-never-covers-another-cell-3' => ['ansi'],
            '588-a-block-quote-and-a-footnote-body-take-the-first-block-form' => ['ansi'],
            '108-security-hardening-12' => ['md'],
        ];
        foreach ($rows as $slug => $targets) {
            foreach ($targets as $target) {
                yield $slug . ' ' . $target => [$slug, $target];
            }
        }
    }

    #[DataProvider('cases')]
    public function testWriterMatchesPinnedCarveJs(string $slug, string $target): void
    {
        $source = file_get_contents(__DIR__ . '/../../spec/tests/corpus/' . $slug . '.crv');
        $expected = file_get_contents(__DIR__ . '/../../fixtures/writer-parity/' . $slug . '.' . $target);
        self::assertIsString($source);
        self::assertIsString($expected);

        $actual = match ($target) {
            'md' => CarveConverter::markdown()->convert($source),
            'plain' => CarveConverter::plainText()->convert($source),
            'ansi' => CarveConverter::ansi()->convert($source),
        };

        self::assertSame($expected, $actual);
    }
}
