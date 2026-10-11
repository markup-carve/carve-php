<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class QuotedLiteralCommentOwnershipTest extends TestCase
{
    public function testPairedLiteralCommentsPreserveTheirRealCloser(): void
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/quoted_literal_comment_ownership.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(186, $rows);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($rows as $row) {
            $actual = preg_replace('/>\s+</', '><', $converter->convert($row['source']));
            $this->assertSame($row['html'], trim((string)$actual), $row['id'] . "\n" . $row['source']);
            $restored = $codec->decode($codec->encode($converter->parse($row['source'])));
            $actual = preg_replace('/>\s+</', '><', $converter->render($restored));
            $this->assertSame($row['html'], trim((string)$actual), $row['id'] . ' AST round trip');
        }
    }
}
