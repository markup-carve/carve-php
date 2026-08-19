<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class DefinitionPrepassGateTest extends TestCase
{
    public function testAbsentOpeningBytesSkipWholeDocumentCollectors(): void
    {
        $parser = $this->parser();
        $parser->parse("# Heading\n\nPlain *strong* text with [a link](/url).\n");

        $this->assertSame(['references' => 0, 'footnotes' => 0, 'abbreviations' => 0], $parser->calls);
    }

    public function testEachBroadGateReachesItsCollector(): void
    {
        $parser = $this->parser();
        $parser->parse("[ref]: /url\n\n[^note]: body\n\n*[HTML]: HyperText\n");

        $this->assertSame(['references' => 1, 'footnotes' => 1, 'abbreviations' => 1], $parser->calls);
    }

    private function parser(): BlockParser
    {
        return new class extends BlockParser {
            /**
             * @var array{references: int, footnotes: int, abbreviations: int}
             */
            public array $calls = ['references' => 0, 'footnotes' => 0, 'abbreviations' => 0];

            protected function extractReferences(array $lines): void
            {
                $this->calls['references']++;
                parent::extractReferences($lines);
            }

            protected function extractFootnotes(array $lines): void
            {
                $this->calls['footnotes']++;
                parent::extractFootnotes($lines);
            }

            protected function extractAbbreviations(array $lines): void
            {
                $this->calls['abbreviations']++;
                parent::extractAbbreviations($lines);
            }
        };
    }
}
