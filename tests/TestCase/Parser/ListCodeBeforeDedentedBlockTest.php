<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListCodeBeforeDedentedBlockTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string, array<string>}>
     */
    public static function cases(): iterable
    {
        /** @var array<array{id:string, source:string, html:string, codePayloads:array<string>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/list_code_before_dedented_blocks.json'),
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
     * @param array<string> $expectedPayloads
     */
    #[DataProvider('cases')]
    public function testAnAdmittedCodeBlockSurvivesADedentedFollower(string $source, string $expected, array $expectedPayloads): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $restored = $codec->decode($codec->encode($converter->parse($source)));
        foreach ([$converter->convert($source), $converter->render($restored)] as $html) {
            $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
            preg_match_all('@<pre><code[^>]*>(.*?)</code></pre>@s', $html, $payloads);
            $this->assertSame($expectedPayloads, $payloads[1]);
        }
    }

    public function testTheSyntheticCloserDoesNotExtendSourcePositions(): void
    {
        $source = "- para\n    ```\n    code\n# heading\n    ```\n";
        $tree = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
        $item = $tree['children'][0]['items'][0];
        $code = $item['children'][1];
        $this->assertSame('code_block', $code['type']);
        $pos = $code['pos'];
        $this->assertSame("```\n    code", substr($source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));
        $this->assertSame(3, $item['pos']['endLine']);
        $this->assertSame($pos['endOffset'], $item['pos']['endOffset']);
    }
}
