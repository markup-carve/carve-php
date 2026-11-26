<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Import\HtmlNode;
use MarkupCarve\Carve\Import\HtmlParser;
use PHPUnit\Framework\TestCase;

final class LegacyDeclarationTest extends TestCase
{
    public function testSyntheticUtf8DeclarationDoesNotBecomeAnImportedComment(): void
    {
        $html = '<!DOCTYPE html><html><head><title>T</title></head><body>x</body></html>';
        $root = HtmlParser::parse($html, true);
        self::assertSame([HtmlNode::DOCTYPE, HtmlNode::ELEMENT], array_map(
            static fn (HtmlNode $node): string => $node->kind,
            $root->children,
        ));
    }

    public function testAuthoredCommentBeforeDoctypeIsPreserved(): void
    {
        $html = '<!-- kept --><!DOCTYPE html><html><body>x</body></html>';
        $root = HtmlParser::parse($html, true);
        $comments = array_values(array_filter(
            $root->children,
            static fn (HtmlNode $node): bool => $node->kind === HtmlNode::COMMENT,
        ));
        self::assertCount(1, $comments);
        self::assertSame(' kept ', $comments[0]->name);
    }
}
