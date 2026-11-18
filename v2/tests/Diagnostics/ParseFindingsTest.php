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
            'C2-B-122 further words' => ["```js T\nx\n```", [['fence-title-syntax', '/children/0', 6, 7]]],
            'C2-B-122 second space' => ["```  b\nx\n```", [['fence-title-syntax', '/children/0', 5, 6]]],
            'C2-B-122 after the label' => ["```js \"T\" [l] x\nx\n```", [['fence-title-syntax', '/children/0', 14, 15]]],
            'C2-B-122 glued to the title' => ["```js \"T\"x\nx\n```", [['fence-title-syntax', '/children/0', 9, 10]]],
            'C2-B-122 sigil div' => ["::: > x\ny\n:::", [['fence-title-syntax', '/children/0', 6, 7]]],
            'C2-B-225 raw format' => ["```=html~\nx\n```", [['fence-title-syntax', '/children/0', 8, 9]]],
            'C2-B-225 div name' => ["::: glossary>\nx\n:::", [['fence-title-syntax', '/children/0', 12, 13]]],
            'C2-B-117 title' => ["``` js \"T\"\nx\n```", []],
            'C2-B-105 glued' => ["```js{.x}\nx\n```", [['fence-opener-fallback', '/children/0', 5, 9]]],
            'C2-B-122 language' => ["```js {.x}\nx\n```", [['fence-title-syntax', '/children/0', 6, 10]]],
            'C2-B-122 admonition' => ["::: note {.x}\nx\n:::", [['fence-title-syntax', '/children/0', 9, 13]]],
            'C2-B-105 glued div name' => ["::: foo{.x}\nx\n:::", [['fence-opener-fallback', '/children/0', 7, 11]]],
            'C2-B-122 spaced after a class name' => ["::: foo {.x}\nx\n:::", [['fence-title-syntax', '/children/0', 8, 12]]],
            'C2-B-181 unclosed quote' => ["{table}\n```csv\na,\"b\nc\n```", [['delimited-table-malformed', '/children/0', 17, 18]]],
            'C2-B-181 doubled quote at the end' => [
                "{table}\n```csv\na,\"b\"\"\n```", [
                    [
                        'delimited-table-malformed',
                        '/children/0', 17, 18,
                    ],
                ],
            ],
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function messages(): array
    {
        return [
            'unclosed-code-fence' => [
                "- a\n\n  ```\n  x\n- b", 'The ``` fence opened here has no closing fence; it ends at the end of its '
                . 'container on line 4. Add a closing ``` where the code should stop.',
            ],
            'unclosed-comment-fence' => [
                "%%%\nx", 'The %%% comment fence opened here has no closing fence and hides everything to the end '
                . 'of the document. Add a closing %%% where the comment should stop.',
            ],
            'unclosed-container-fence' => [
                "::: |\na", 'This 3-colon line block has no closer; it runs to the end of its container. '
                . 'Add a bare fence of 3 colons to close it.',
            ],
            'fence-title-syntax' => [
                "```js T\nx\n```", 'Text after "js" must be a quoted title or a [label]; it is not read as a title. '
                . 'Write ``` js "T".',
            ],
            'fence-opener-fallback' => [
                "::: note {.c}\nx\n:::", 'Text after "note" must be a quoted title or a [label]; it is not read as a title. '
                . 'Write ::: note "{.c}".',
            ],
            'fence-title-syntax div spaces' => [
                ":::  \"T\nx\n:::",
                'Text after ":::" must be a quoted title or a [label]; it is not read as a title. Write ::: ""T".',
            ],
            'duplicate-footnote-definition' => [
                "[^a]: x\n\n[^a]: y", 'Duplicate footnote definition [^a] is ignored; the first '
                . 'definition for a label wins.',
            ],
            'duplicate-id' => [
                "{#a}\nx\n\n{#a}\ny", 'Duplicate authored id "a": the first node in document order wins id lookup. Change one id.',
            ],
            'include-unresolved' => ['{{ /etc/x.crv }}', 'Include "[absolute path]" could not be resolved.'],
            'carrier-attribute-dropped' => [
                '[x]{data-carve-raw=html}', 'Dropped the authored attribute data-carve-raw: the name is '
                . 'reserved for roundtrip import',
            ],
        ];
    }

    #[DataProvider('messages')]
    public function testC2A173MessagesFillTheSlotsOfTheCatalog(string $source, string $message): void
    {
        $found = array_map(static fn (Diagnostic $d): string => $d->message, Carve2::renderWithDiagnostics($source)->diagnostics);
        $this->assertContains($message, $found);
        foreach ($found as $text) {
            $this->assertStringNotContainsString('${', $text);
        }
    }
}
