<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnmatchedInlineCodeListParagraphTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paragraphs(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/unmatched_inline_code_list_paragraphs.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('paragraphs')]
    public function testAnUnmatchedCodeRunKeepsItsParagraph(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $restored = $codec->decode($codec->encode($converter->parse($source)));
        foreach ([$converter->convert($source), $converter->render($restored)] as $html) {
            $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
        }
    }

    public function testSuccessiveFenceBlocksKeepTheirCodePayloads(): void
    {
        /** @var array<array{id:string, source:string, codePayloads:array<string>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/successive_fence_code_payloads.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($rows as $row) {
            $restored = $codec->decode($codec->encode($converter->parse($row['source'])));
            foreach ([$converter->convert($row['source']), $converter->render($restored)] as $html) {
                preg_match_all('@<code(?:\s[^>]*)?>(.*?)</code>@s', $html, $payloads);
                $this->assertSame($row['codePayloads'], $payloads[1], $row['id']);
            }
        }
    }
}
