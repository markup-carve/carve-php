<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6702, C2-B-138: the fence of a delimited table has closed before a comment-only line below it, so the
 * comment between the table and its caption is the sibling after the table, not a child of it.
 */
final class DelimitedTableCaptionCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function sources(): array
    {
        return [
            'csv, line comment' => ["{table}\n```csv\nName\nAda\n```\n%% source: survey\n^ Members\n", ['table', 'comment']],
            'tsv, two comments' => ["{table}\n```tsv\nA\tB\n```\n%% a\n%% b\n^ Cap\n", ['table', 'comment', 'comment']],
            'csv, longer fence' => ["{table}\n````csv\nA\n````\n%% n\n^ Cap\n", ['table', 'comment']],
            'pipe table keeps it' => ["|= Name |\n| Ada |\n%% note\n^ Cap\n", ['table']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $types
     */
    #[DataProvider('sources')]
    public function testTheCommentIsASiblingOfADelimitedTable(string $source, array $types): void
    {
        $doc = Carve::parse($source);

        $this->assertSame($types, array_map(static fn ($node): string => $node->type, $doc->children ?? []));
        $this->assertArrayHasKey('caption', $doc->children[0]->f ?? []);
        $tableChildren = array_map(static fn ($node): string => $node->type, $doc->children[0]->children ?? []);
        $this->assertSame(count($types) === 1, in_array('comment', $tableChildren, true));
    }

    public function testFormatKeepsTheDelimitedTableAndTheComment(): void
    {
        $source = "{table}\n```csv\nName\nAda\n```\n%% source: survey\n^ Members\n";

        $this->assertSame("{table}\n```csv\nName\nAda\n```\n^ Members\n\n%% source: survey\n", Carve::format($source));
    }

    public function testTheCommentInsideAQuoteStaysInTheQuote(): void
    {
        $doc = Carve::parse("> {table}\n> ```csv\n> A\n> ```\n> %% n\n> ^ Cap\n");
        $quote = $doc->children[0] ?? null;

        $this->assertNotNull($quote);
        $this->assertSame(['table', 'comment'], array_map(static fn ($node): string => $node->type, $quote->children ?? []));
    }
}
