<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Diagnostics;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParseFindingsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<array{0: string, 1: string, 2: int, 3: int}>}>
     */
    public static function cases(): array
    {
        return [
            'C2-B-111 code' => ["```js\nx", [['unclosed-code-fence', '/children/0', 0, 7]]],
            'C2-B-111 comment' => ["%%%\nx", [['unclosed-comment-fence', '/children/0', 0, 5]]],
            'C2-B-111 div' => [":::\nx", [['unclosed-container-fence', '/children/0', 0, 5]]],
            'C2-B-111 line block' => ["::: |\na", [['unclosed-container-fence', '/children/0', 0, 7]]],
            'C2-B-132 stray closer' => [
                "- a\n  :::\n  x\n:::", [
                    ['unclosed-container-fence', '/children/0/children/0/children/1', 6, 13],
                    ['unclosed-container-fence', '/children/1', 14, 17],
                ],
            ],
            'C2-B-163' => ["---\na", [['unclosed-frontmatter', '/children/0', 0, 5]]],
            'C2-B-111 closed' => ["```js\nx\n```\n\n:::\ny\n:::", []],
            'C2-B-122 further words' => ["```js T\nx\n```", [['fence-title-syntax', '/children/0', 0, 13]]],
            'C2-B-122 second space' => ["```  b\nx\n```", [['fence-title-syntax', '/children/0', 0, 12]]],
            'C2-B-122 after the label' => ["```js \"T\" [l] x\nx\n```", [['fence-title-syntax', '/children/0', 0, 21]]],
            'C2-B-122 glued to the title' => ["```js \"T\"x\nx\n```", [['fence-title-syntax', '/children/0', 0, 16]]],
            'C2-B-122 sigil div' => ["::: > x\ny\n:::", [['fence-title-syntax', '/children/0', 0, 13]]],
            'C2-B-225 raw format' => ["```=html~\nx\n```", [['fence-title-syntax', '/children/0', 0, 15]]],
            'C2-B-225 div name' => ["::: glossary>\nx\n:::", [['fence-title-syntax', '/children/0', 0, 19]]],
            'C2-B-117 title' => ["``` js \"T\"\nx\n```", []],
            'C2-B-105 glued' => ["```js{.x}\nx\n```", [['fence-opener-fallback', '/children/0', 0, 15]]],
            'C2-B-105 language' => ["```js {.x}\nx\n```", [['fence-opener-fallback', '/children/0', 0, 16]]],
            'C2-B-105 admonition' => ["::: note {.x}\nx\n:::", [['fence-opener-fallback', '/children/0', 0, 19]]],
            'C2-B-105 glued div name' => ["::: foo{.x}\nx\n:::", [['fence-opener-fallback', '/children/0', 0, 17]]],
            'C2-B-122 spaced after a class name' => ["::: foo {.x}\nx\n:::", [['fence-title-syntax', '/children/0', 0, 18]]],
            'C2-B-181 unclosed quote' => ["{table}\n```csv\na,\"b\nc\n```", [['delimited-table-malformed', '/children/0', 0, 25]]],
            'C2-B-181 doubled quote at the end' => ["{table}\n```csv\na,\"b\"\"\n```", [['delimited-table-malformed', '/children/0', 0, 25]]],
            'C2-B-181 closed quote' => ["{table}\n```csv\na,\"b\"\n```", []],
            'C2-B-085 link' => ["[a]: /x\n[a]: /y", [['duplicate-link-reference-definition', '/children/1', 8, 15]]],
            'C2-B-085 footnote' => ["[^a]: x\n\n[^a]: y", [['duplicate-footnote-definition', '/children/1', 9, 16]]],
            'C2-B-085 abbreviation' => ["*[A]: x\n*[A]: y", [['duplicate-abbreviation-definition', '/children/1', 8, 15]]],
            'C2-A-039' => [
                "{#x}\n# A\n\n{#x}\npara\n\n[t]{#x} and *u*{#x}", [
                    ['duplicate-id', '/children/1', 10, 19],
                    ['duplicate-id', '/children/2/children/0', 21, 28],
                    ['duplicate-id', '/children/2/children/2', 33, 40],
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testC2B111ParseFinding(string $source, array $expected): void
    {
        $got = array_map(
            static fn (Diagnostic $d): array => [$d->code, (string)$d->path, $d->startOffset, $d->endOffset],
            Carve2::parseWithDiagnostics($source)->diagnostics,
        );
        $this->assertSame($expected, $got);
        foreach (Carve2::parseWithDiagnostics($source)->diagnostics as $d) {
            $this->assertSame('parse', $d->channel);
        }
    }

    public function testC2B122FurtherWordsKeepNoTitle(): void
    {
        $tree = json_decode(Carve2::toJson("```  \"T\"\nx\n```"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('title', $tree['children'][0]);
        $this->assertArrayNotHasKey('syntax', $tree['children'][0]);
    }
}
