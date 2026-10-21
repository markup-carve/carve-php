<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LiteralPayloadNewlineSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function corpusCases(): array
    {
        return [
            '291-3 code' => ['291-a-fence-keeps-the-blank-line-at-the-end-of-its-content-3', 'code_block', 16],
            '291-5 code' => ['291-a-fence-keeps-the-blank-line-at-the-end-of-its-content-5', 'code_block', 9],
            '291-6 code' => ['291-a-fence-keeps-the-blank-line-at-the-end-of-its-content-6', 'code_block', 11],
            '365-2 code' => ['365-a-blank-line-before-a-sibling-marker-separates-the-items-whatever-consumed-it-2', 'code_block', 11],
            '365-3 code' => ['365-a-blank-line-before-a-sibling-marker-separates-the-items-whatever-consumed-it-3', 'code_block', 15],
            '366-2 raw' => ['366-a-raw-block-keeps-the-blank-line-at-the-end-of-its-payload-too-2', 'raw_block', 16],
            '521-8 raw' => ['521-a-zero-line-and-a-one-blank-raw-payload-are-not-the-same-block-8', 'raw_block', 19],
            '521-8 quote' => ['521-a-zero-line-and-a-one-blank-raw-payload-are-not-the-same-block-8', 'block_quote', 19],
            '619-10 code' => ['619-a-fence-run-that-dedents-out-of-a-child-keeps-the-outer-item-tight-10', 'code_block', 39],
            '620-21 code' => ['620-generated-container-documents-21', 'code_block', 15],
            '626-8 code' => ['626-an-indented-plus-below-a-nested-fence-stays-content-at-every-depth-8', 'code_block', 25],
            '638-2 code' => ['638-nested-plus-below-fence-and-description-2', 'code_block', 19],
            '638 base code' => ['638-nested-plus-below-fence-and-description', 'code_block', 9],
        ];
    }

    #[DataProvider('corpusCases')]
    public function testLiteralPayloadNewlineIsInsideItsSpan(string $slug, string $type, int $expectedEnd): void
    {
        $source = file_get_contents(__DIR__ . '/../../spec/tests/corpus/' . $slug . '.crv');
        self::assertIsString($source);
        $node = $this->lastOfType($source, $type);
        self::assertNotNull($node, $slug);
        self::assertIsArray($node['pos']);
        self::assertIsInt($node['pos']['endOffset']);
        self::assertSame($expectedEnd, $node['pos']['endOffset'], $slug);
        self::assertSame("\n", $source[$expectedEnd - 1], $slug);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function controls(): array
    {
        return [
            'closed code fence stops at closer' => ["```\nx\n```\n", 'code_block', 9],
            'closed raw fence stops at closer' => ["```=html\nx\n```\n", 'raw_block', 14],
            'unclosed code without final LF' => ["```\nx", 'code_block', 5],
            'unclosed raw without final LF' => ["```=html\nx", 'raw_block', 10],
        ];
    }

    #[DataProvider('controls')]
    public function testFollowingOrMissingNewlineDoesNotExtendTheSpan(string $source, string $type, int $expectedEnd): void
    {
        $node = $this->lastOfType($source, $type);
        self::assertNotNull($node);
        self::assertIsArray($node['pos']);
        self::assertIsInt($node['pos']['endOffset']);
        self::assertSame($expectedEnd, $node['pos']['endOffset']);
    }

    public function testIndependentLiteralSpansGrowLinearly(): void
    {
        $measure = static function (int $count): int {
            LayoutWork::reset();
            LayoutWork::$on = true;
            try {
                $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
                $converter->parse(str_repeat("```\nx\n\n```\n\n", $count));
            } finally {
                LayoutWork::$on = false;
            }

            return LayoutWork::$spanStamps;
        };

        $small = $measure(128);
        $large = $measure(256);
        self::assertSame(128, $small);
        self::assertSame(256, $large);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastOfType(string $source, string $type): ?array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $root = (new AstCodec())->encode($converter->parse($source));
        $last = null;
        $walk = static function (array $node) use (&$walk, &$last, $type): void {
            if (($node['type'] ?? null) === $type) {
                if ($last === null || $node['pos']['endOffset'] > $last['pos']['endOffset']) {
                    $last = $node;
                }
            }
            foreach (['children', 'items', 'rows', 'cells'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk($root);

        return $last;
    }
}
