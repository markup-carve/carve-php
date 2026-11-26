<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-120 (carve-php#6037): the quoted title of a div, an admonition or a directive is inline content, so the data
 * walk reads its references and text runs. A code fence title stays one text node.
 */
final class ContainerTitleInlineTest extends TestCase
{
    /**
     * The rows of carve-js lint/container-title-inline.test.mjs.
     *
     * @return array<string, array{0: string, 1: array<string>, 2?: array<string, mixed>}>
     */
    public static function rows(): array
    {
        $underline = ['rules' => ['deprecated-bare-underline' => 'on']];

        return [
            'footnote reference in an admonition title' => ["::: note \"[^a]\"\n:::\n\n[^a]: B\n", []],
            'link reference in a div title' => ["::: \"[x][r]\"\n:::\n\n[r]: /u\n", []],
            'code fence title stays text' => ["``` \"[^a]\"\nx\n```\n\n[^a]: B\n", ['unused-footnote-definition 18']],
            'unresolved references in a quoted title' => [
                "> ::: tip \"[^b] [x][q]\"\n> :::\n",
                ['unresolved-footnote 11', 'unresolved-reference-link 16'],
            ],
            'text run of a div title' => ["::: \"_x_\"\n:::\n", ['deprecated-bare-underline 5'], $underline],
            'text run of a code fence title' => ["``` \"_x_\"\nx\n```\n", ['deprecated-bare-underline 5'], $underline],
            'nested inline in a div title' => ["::: \"*[x][r]*\"\n:::\n\n[r]: /u\n", []],
            'reference in the body still counts' => ["::: \"t\"\n[^a]\n:::\n\n[^a]: B\n", []],
            'unused definition beside a title reference' => [
                "::: \"[^b]\"\nx\n:::\n\n[^a]: B\n[^b]: C\n",
                ['unused-footnote-definition 18'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     * @param array<string, mixed> $options
     *
     * @return void
     */
    #[DataProvider('rows')]
    public function testRow(string $source, array $expected, array $options = []): void
    {
        $found = [];
        foreach (Linter::lint($source, $options) as $diagnostic) {
            $found[] = $diagnostic->code . ' ' . $diagnostic->startOffset;
        }
        sort($found);

        $this->assertSame($expected, $found);
    }
}
