<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockGrammar;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PendingDefinitionTermBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string>}>
     */
    public static function paragraphs(): iterable
    {
        /** @var array<array{id:string, source:string, html:string, codePayloads:array<string>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/pending_definition_term_boundaries.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html'], $row['codePayloads']];
        }
    }

    /**
     * @param string $source
     * @param string $expected
     * @param array<string> $codePayloads
     */
    #[DataProvider('paragraphs')]
    public function testCodeKeepsBoundaryBlanksAndLiteralPayload(string $source, string $expected, array $codePayloads): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $restored = $codec->decode($codec->encode($converter->parse($source)));
        foreach ([$converter->convert($source), $converter->render($restored)] as $html) {
            $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
            preg_match_all('@<pre[^>]*><code[^>]*>(.*?)</code></pre>@s', $html, $payloads);
            $this->assertSame($codePayloads, $payloads[1]);
            $this->assertStringNotContainsString(BlockGrammar::LAZY_FRAME, $html);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function positions(): iterable
    {
        /** @var array<array{source:string, ast:array<string, mixed>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/pending_definition_term_positions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $index => $row) {
            yield 'below-column attributes ' . $index => [$row['source'], $row['ast']];
        }
    }

    /**
     * @param string $source
     * @param array<string, mixed> $expected
     */
    #[DataProvider('positions')]
    public function testBelowColumnAttributesKeepAstPositions(string $source, array $expected): void
    {
        $converter = new CarveConverter(parser: new BlockParser(trackPositions: true));
        $this->assertSame($expected, (new AstCodec())->encode($converter->parse($source)));
    }
}
