<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class BuilderHookCompatibilityTest extends TestCase
{
    public function testExtractedBuildersHonorProtectedOverrides(): void
    {
        $parser = new class extends BlockParser {
            public array $calls = [];

            /**
             * @param \MarkupCarve\Carve\Node\Node $item
             * @param array<string> $lines
             * @param array<int, int>|null $lineMap
             * @param int|null $leadNestedColumn
             * @param array<int, true>|null $authoredBaseEligible
             */
            protected function parseItemBlocks(
                Node $item,
                array $lines,
                ?array $lineMap = null,
                ?array $authoredBaseEligible = null,
                ?int $leadNestedColumn = null,
            ): void {
                $this->calls[__FUNCTION__] = true;

                parent::parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
            }

            /**
             * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
             * @param list<array{0: string, 1: int}> $lines
             */
            protected function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
            {
                $this->calls[__FUNCTION__] = true;

                parent::appendLineBlockStanza($lineBlock, $lines);
            }

            /**
             * @param string $line
             * @param int $lineNo
             *
             * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
             */
            protected function expandLineBlockLine(string $line, int $lineNo): array
            {
                $this->calls[__FUNCTION__] = true;

                return parent::expandLineBlockLine($line, $lineNo);
            }

            /**
             * @param string $line
             *
             * @return array{length: int, attrs: string|null}|null
             */
            protected function parseLineBlockOpener(string $line): ?array
            {
                $this->calls[__FUNCTION__] = true;

                return parent::parseLineBlockOpener($line);
            }

            /**
             * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
             * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
             */
            protected function convertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
            {
                $this->calls[__FUNCTION__] = true;

                parent::convertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
            }

            /**
             * @return array{header: bool, align: string|null, valign: string|null, content: string}
             */
            protected function parseTableCellMarker(string $raw, bool $markerOnly = false): array
            {
                $this->calls[__FUNCTION__] = true;

                return parent::parseTableCellMarker($raw, $markerOnly);
            }

            /**
             * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
             * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
             *
             * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
             */
            protected function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
            {
                $this->calls[__FUNCTION__] = true;

                return parent::resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
            }

            /**
             * @param array<string> $lines
             * @param int $start
             */
            protected function blockQuoteLazyExtentEnd(array $lines, int $start): int
            {
                $this->calls[__FUNCTION__] = true;

                return parent::blockQuoteLazyExtentEnd($lines, $start);
            }

            /**
             * @param string $line
             * @param array<string>|null $lines
             * @param int|null $index
             */
            protected function endsDefinitionTerm(string $line, ?array $lines = null, ?int $index = null): bool
            {
                $this->calls[__FUNCTION__] = true;

                return parent::endsDefinitionTerm($line, $lines, $index);
            }

            /**
             * @param string $line
             * @param bool $paragraphOpen Whether an open paragraph precedes this line.
             * @param array<string>|null $lines
             * @param int|null $index
             */
            protected function endsBlockQuote(
                string $line,
                bool $paragraphOpen,
                ?array $lines = null,
                ?int $index = null,
            ): bool {
                $this->calls[__FUNCTION__] = true;

                return parent::endsBlockQuote($line, $paragraphOpen, $lines, $index);
            }
        };
        $parser->parse("- first\n  second\n  - nested\n\n::: |\nverse\nnext\n:::\n\n| a | b |\n|---|---|\n| c | d |\n\n> quote\n> continuation\noutside\n\n:: Term\ncontinued\n: definition\n");

        $parser->parse("- first\n  > quote\n  lazy\n  [ref]: /x\n");

        self::assertArrayHasKey('blockQuoteLazyExtentEnd', $parser->calls);
        self::assertArrayHasKey('parseItemBlocks', $parser->calls);
        self::assertArrayHasKey('appendLineBlockStanza', $parser->calls);
        self::assertArrayHasKey('expandLineBlockLine', $parser->calls);
        self::assertArrayHasKey('parseLineBlockOpener', $parser->calls);
        self::assertArrayHasKey('convertParagraphSoftBreaksToHardBreaks', $parser->calls);
        self::assertArrayHasKey('parseTableCellMarker', $parser->calls);
        self::assertArrayHasKey('resolveRowSpans', $parser->calls);
        self::assertArrayHasKey('endsDefinitionTerm', $parser->calls);
        self::assertArrayHasKey('endsBlockQuote', $parser->calls);
    }
}
