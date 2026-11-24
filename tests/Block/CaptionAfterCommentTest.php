<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6635, C2-A-082, C2-B-138: only a table or a figure group takes the comments between it and its caption as
 * children; behind any other block they stay its siblings.
 */
final class CaptionAfterCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function sources(): array
    {
        return [
            'code include' => ["{{ v.py @code }}\n%% note\n^ Listing\n", ['include', 'comment']],
            'code include, two comments' => ["{{ v.py @code }}\n%% a\n%% b\n^ Listing\n", ['include', 'comment', 'comment']],
            'code block' => ["```\nx\n```\n%% note\n^ Listing\n", ['code_block', 'comment']],
            'table' => ["|a|\n%% note\n^ Cap\n", ['table']],
            'figure group' => ["::: figure\nx\n:::\n%% note\n^ Cap\n", ['figure_group']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $types
     */
    #[DataProvider('sources')]
    public function testTheCommentStaysWhereTheBlockTakesIt(string $source, array $types): void
    {
        $doc = Carve::parse($source);

        $this->assertSame($types, array_map(static fn ($node): string => $node->type, $doc->children ?? []));
        $this->assertArrayHasKey('caption', $doc->children[0]->f ?? []);
    }

    public function testTheIncludeRendersAndFormatKeepsTheComment(): void
    {
        $source = "{{ v.py @code }}\n%% note\n^ Listing\n";

        $this->assertSame("<p>{{ v.py @code }}</p>\n<p>Listing</p>", Carve::toHtml($source));
        $this->assertSame("{{ v.py @code }}\n^ Listing\n\n%% note\n", Carve::format($source));
    }
}
