<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\AbbreviationLayoutTracker;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\DefinitionLayoutEvent;
use MarkupCarve\Carve\Parser\ReferenceDefinitionExtractor;
use PHPUnit\Framework\TestCase;

class DefinitionLayoutEventTest extends TestCase
{
    public function testTheSharedWalkEmitsOnlyDefinitionsVisibleAtTheirContainer(): void
    {
        $lines = [
            '[r]: /url',
            '[^n]: note',
            '*[HTML]: Hyper Text',
            '```',
            '[hidden]: /no',
            '[^hidden]: no',
            '*[NO]: no',
            '```',
            '- item',
            '  [nested]: /nested',
            '  [^nested]: yes',
        ];
        $extractor = new ReferenceDefinitionExtractor();

        $extractor->extract($lines, true, true);

        $actual = array_map(
            static fn (DefinitionLayoutEvent $event): array => [
                $event->kind,
                $event->line,
                $event->contentColumn,
                $event->reachedColumn,
                $event->subject,
                $event->inList,
            ],
            $extractor->getLayoutEvents(),
        );
        $this->assertSame(
            [
                [DefinitionLayoutEvent::REFERENCE, 0, 0, 0, '[r]: /url', false],
                [DefinitionLayoutEvent::FOOTNOTE, 1, 0, 0, '[^n]: note', false],
                [DefinitionLayoutEvent::ABBREVIATION, 2, 0, 0, '*[HTML]: Hyper Text', false],
                [DefinitionLayoutEvent::REFERENCE, 9, 2, 2, '[nested]: /nested', true],
                [DefinitionLayoutEvent::FOOTNOTE, 10, 2, 2, '[^nested]: yes', true],
            ],
            $actual,
        );
    }

    public function testTheAbbreviationTrackerClosesVerseAndOrdinaryDivs(): void
    {
        $lines = ['::: |', ':::', '::: note', ':::'];
        $tracker = new AbbreviationLayoutTracker($lines);

        foreach ($lines as $index => $line) {
            $this->assertNull($tracker->observe($line, $index));
        }
    }

    public function testTheIsolatedFootnoteCollectorRetainsItsOpaqueFallback(): void
    {
        $parser = new class extends BlockParser {
            /**
             * @param list<string> $lines
             *
             * @return array<string, \MarkupCarve\Carve\Node\FootnoteDefinition>
             */
            public function collectFootnotes(array $lines): array
            {
                $this->extractFootnotes($lines);

                return $this->footnotes;
            }
        };

        $this->assertSame(
            [],
            $parser->collectFootnotes([
                '```',
                '[^code]: hidden',
                '```',
                '%%% comment',
                '[^comment]: hidden',
                '%%%',
            ]),
        );
    }
}
