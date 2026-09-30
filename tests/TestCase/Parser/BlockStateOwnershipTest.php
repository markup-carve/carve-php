<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class BlockStateOwnershipTest extends TestCase
{
    public function testReusingASubclassDoesNotKeepDocumentDefinitions(): void
    {
        $parser = new class extends BlockParser {
            public function referenceLabels(): array
            {
                return array_keys($this->references);
            }
        };
        $renderer = new HtmlRenderer();
        $first = $renderer->render($parser->parse("[name]: /first\n\n[name][]\n"));
        self::assertStringContainsString('href="/first"', $first);
        self::assertContains('name', $parser->referenceLabels());

        $second = $renderer->render($parser->parse("[name][]\n"));
        self::assertSame([], $parser->referenceLabels());
        self::assertStringNotContainsString('href="/first"', $second);
        self::assertSame($renderer->render((new BlockParser())->parse("[name][]\n")), $second);
    }

    public function testNestedFailureRestoresTheParentFrameAndAllowsReuse(): void
    {
        $parser = new class extends BlockParser {
            public bool $failNested = true;

            protected function parseBlocks(
                Node $parent,
                array $lines,
                int $indent,
                ?array $lineMap = null,
                bool $topLevel = false,
                bool $itemBody = false,
            ): void {
                if ($this->failNested && !$topLevel) {
                    throw new RuntimeException('nested failure');
                }
                $before = [$this->currentLineMap, $this->currentContentColumns];
                try {
                    parent::parseBlocks($parent, $lines, $indent, $lineMap, $topLevel, $itemBody);
                } finally {
                    TestCase::assertSame($before, [$this->currentLineMap, $this->currentContentColumns]);
                }
            }
        };
        $source = "- first\n  - nested\n\nAfter\n";
        try {
            $parser->parse($source);
            self::fail('Expected nested parsing to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('nested failure', $exception->getMessage());
        }
        $parser->failNested = false;
        $renderer = new HtmlRenderer();
        self::assertSame($renderer->render((new BlockParser())->parse($source)), $renderer->render($parser->parse($source)));
    }

    public function testClonedParserOwnsItsDefinitionsAndBuilderHooks(): void
    {
        $parser = new class extends BlockParser {
            public int $items = 0;

            protected function parseItemBlocks(
                Node $item,
                array $lines,
                ?array $lineMap = null,
                ?array $authoredBaseEligible = null,
                ?int $leadNestedColumn = null,
            ): void {
                $this->items++;
                parent::parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
            }
        };
        $renderer = new HtmlRenderer();
        $parser->parse("[ref]: /original\n\n- [ref][]\n");
        $before = $parser->items;
        $copy = clone $parser;
        $output = $renderer->render($copy->parse("[ref]: /copy\n\n- [ref][]\n"));

        self::assertStringContainsString('href="/copy"', $output);
        self::assertSame($before, $parser->items);
        self::assertGreaterThan($before, $copy->items);
        self::assertSame('/original', $parser->getReference('ref')->url);
        self::assertSame('/copy', $copy->getReference('ref')->url);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function sourceTrackingModes(): array
    {
        return [
            'disabled' => [false, false],
            'lines' => [true, false],
            'positions' => [false, true],
            'lines and positions' => [true, true],
        ];
    }

    #[DataProvider('sourceTrackingModes')]
    public function testClonedParserOwnsItsSourceMappings(bool $trackSourceLines, bool $trackPositions): void
    {
        $parser = new BlockParser(trackSourceLines: $trackSourceLines, trackPositions: $trackPositions);
        $codec = new AstCodec();
        $originalSource = "# Original\n\n- first\n  second\n";
        $original = $codec->encode($parser->parse($originalSource));
        $copy = clone $parser;
        $copySource = "Intro é\n\n> - other\n>   continued\n\nAfter\n";
        $expected = $codec->encode((new BlockParser(trackSourceLines: $trackSourceLines, trackPositions: $trackPositions))->parse($copySource));
        $actual = $codec->encode($copy->parse($copySource));

        self::assertSame($expected, $actual);
        self::assertSame($original, $codec->encode($parser->parse($originalSource)));
        self::assertSame($expected, $codec->encode($copy->parse($copySource)));
    }

    public function testClonedParserKeepsLegacyBlockPatterns(): void
    {
        $parser = new BlockParser();
        $parser->addBlockPattern('/^CUSTOM$/', static function (array $lines, int $start, Node $parent, BlockParser $parser): int {
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text('matched'));
            $parent->appendChild($paragraph);

            return 1;
        });
        $hosts = [];
        $parser->getInlineParser()->addInlinePattern('/@match/', static function (string $match, array $groups, InlineParser $host) use (&$hosts): Text {
            $hosts[] = $host;

            return new Text('inline match');
        });
        $parser->parse('CUSTOM');
        $copy = clone $parser;
        $renderer = new HtmlRenderer();

        self::assertSame("<p>matched</p>\n<p>inline match</p>\n", $renderer->render($copy->parse("CUSTOM\n\n@match")));
        self::assertNotEmpty($hosts);
        foreach ($hosts as $host) {
            self::assertSame($copy->getInlineParser(), $host);
        }
        self::assertSame("<p>matched</p>\n", $renderer->render($parser->parse('CUSTOM')));
    }

    public function testLegacyLineMapChangesReachBothResolversAndStayWithTheirClone(): void
    {
        $parser = new class extends BlockParser {
            public function setLineMap(?array $lineMap): void
            {
                $this->currentLineMap = $lineMap;
            }
        };
        $mapperMethod = new ReflectionMethod(BlockParser::class, 'sourceMapper');
        $lookupMethod = new ReflectionMethod(BlockParser::class, 'sourceLineFor');
        $mapper = $mapperMethod->invoke($parser);
        $lookup = $lookupMethod->getClosure($parser);
        self::assertSame(3, $lookup(3));
        self::assertSame(3, $mapper->sourceLineFor(3));

        $parser->setLineMap([0 => 21, 2 => 0]);
        foreach ([0 => 21, 1 => -1, 2 => 0] as $index => $sourceLine) {
            self::assertSame($sourceLine, $lookup($index));
            self::assertSame($sourceLine, $mapper->sourceLineFor($index));
        }

        $copy = clone $parser;
        $copyMapper = $mapperMethod->invoke($copy);
        $copyLookup = $lookupMethod->getClosure($copy);
        $copy->setLineMap([0 => 9]);
        self::assertSame(9, $copyLookup(0));
        self::assertSame(9, $copyMapper->sourceLineFor(0));
        self::assertSame(21, $lookup(0));
        self::assertSame(21, $mapper->sourceLineFor(0));

        $copy->setLineMap([]);
        self::assertSame(-1, $copyLookup(0));
        self::assertSame(-1, $copyMapper->sourceLineFor(0));
    }
}
