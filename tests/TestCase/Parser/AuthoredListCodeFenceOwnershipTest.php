<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AuthoredListCodeFenceOwnershipTest extends TestCase
{
    public function testAuthoredCodeKeepsItsPayloadAndListBoundary(): void
    {
        /** @var array<array{id:string, source:string, html:string, codePayloads:array<string>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/authored_list_code_fence_ownership.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(484, $rows);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($rows as $row) {
            $restored = $codec->decode($codec->encode($converter->parse($row['source'])));
            foreach ([$converter->convert($row['source']), $converter->render($restored)] as $html) {
                $actual = preg_replace('/>\s+</', '><', $html);
                $this->assertSame(
                    $this->normalizeSpaces($row['html']),
                    $this->normalizeSpaces(trim((string)$actual)),
                    $row['id'] . "\n" . $row['source'],
                );
                preg_match_all('@<pre><code[^>]*>(.*?)</code></pre>@s', $html, $payloads);
                $this->assertSame(
                    array_map($this->normalizeSpaces(...), $row['codePayloads']),
                    array_map($this->normalizeSpaces(...), $payloads[1]),
                    $row['id'] . ' code payload',
                );
            }
        }
    }

    private function normalizeSpaces(string $html): string
    {
        return str_replace('&nbsp;', "\u{00a0}", $html);
    }
}
