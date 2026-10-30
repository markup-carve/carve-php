<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the cross-engine failures reported by carve#1414.
 */
class RawBlockFenceConformanceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function trailingPayloadLines(): array
    {
        return [
            'no trailing blank payload line' => ["```=html\nb\n```\n", "b\n"],
            'one trailing blank payload line' => ["```=html\nb\n\n```\n", "b\n\n"],
            'two trailing blank payload lines' => ["```=html\nb\n\n\n```\n", "b\n\n\n"],
            'an all-blank payload still has its line' => ["```=html\n\n```\n", "\n"],
            'two all-blank payload lines still have both lines' => ["```=html\n\n\n```\n", "\n\n"],
        ];
    }

    #[DataProvider('trailingPayloadLines')]
    public function testRawPayloadKeepsEveryPayloadLineBreak(string $source, string $expected): void
    {
        $document = $this->parse($source);
        $node = $document->getChildren()[0] ?? null;

        self::assertInstanceOf(RawBlock::class, $node);
        self::assertSame($expected, $node->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unterminatedContentColumnFences(): array
    {
        return [
            'list item' => [
                "- q\n  ```\ntail\n",
                "<ul>\n  <li>q\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>tail</p>\n",
            ],
            'definition body' => [
                ":: t\n:  a\n   ```\ntail\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<p>tail</p>\n",
            ],
        ];
    }

    #[DataProvider('unterminatedContentColumnFences')]
    public function testUnterminatedFenceEndsWithItsContainer(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert($source));
    }

    private function parse(string $source): Document
    {
        return (new BlockParser())->parse($source);
    }
}
