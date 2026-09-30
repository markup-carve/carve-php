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
use PHPUnit\Framework\TestCase;
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

    public function testClonedParserOwnsItsSourceMappings(): void
    {
        $parser = new BlockParser(trackSourceLines: true, trackPositions: true);
        $codec = new AstCodec();
        $originalSource = "# Original\n\n- first\n  second\n";
        $original = $codec->encode($parser->parse($originalSource));
        $copy = clone $parser;
        $copySource = "Intro é\n\n> - other\n>   continued\n\nAfter\n";
        $expected = $codec->encode((new BlockParser(trackSourceLines: true, trackPositions: true))->parse($copySource));
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
}
