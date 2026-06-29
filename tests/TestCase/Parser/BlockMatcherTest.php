<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\MatcherContext;
use PHPUnit\Framework\TestCase;

class BlockMatcherTest extends TestCase
{
    public function testBlockMatcherCanEmitCustomBlock(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->addBlockMatcher(function (array $lines, int $start, MatcherContext $ctx): ?array {
            if (($lines[$start] ?? null) !== 'NOTEBLOCK') {
                return null;
            }

            $div = new Div();
            $div->setAttribute('class', 'custom-note');
            foreach ($ctx->parseBlocks(['Body text']) as $child) {
                $div->appendChild($child);
            }

            return ['node' => $div, 'linesConsumed' => 1];
        });

        $this->assertSame("<div class=\"custom-note\">\n  <p>Body text</p>\n</div>", trim($converter->convert('NOTEBLOCK')));
    }

    public function testBlockMatcherIsCoreFirst(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->addBlockMatcher(function (array $lines, int $start, MatcherContext $ctx): ?array {
            if (!str_starts_with($lines[$start], '# ')) {
                return null;
            }

            $para = new Paragraph();
            $para->appendChild(new Text('HIJACKED'));

            return ['node' => $para, 'linesConsumed' => 1];
        });

        $this->assertStringContainsString('<h1>Title</h1>', trim($converter->convert('# Title')));
    }

    public function testAddBlockPatternStillWorks(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->addBlockPattern(
            '/^@@spoiler\s*$/',
            function (array $lines, int $start, $parent, $parser): ?int {
                $end = $start + 1;
                $lineCount = count($lines);
                while ($end < $lineCount && trim($lines[$end]) !== '@@') {
                    $end++;
                }
                if ($end >= $lineCount) {
                    return null;
                }

                $para = new Paragraph();
                $para->appendChild(new Text('SPOILER'));
                $parent->appendChild($para);

                return ($end - $start) + 1;
            },
        );

        $this->assertSame('<p>SPOILER</p>', trim($converter->convert("@@spoiler\nhi\n@@")));
    }

    public function testAddBlockPatternEmittingMultipleSiblingsStaysFlat(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->addBlockPattern(
            '/^DUO\s*$/',
            function (array $lines, int $start, $parent, $parser): ?int {
                $first = new Paragraph();
                $first->appendChild(new Text('A'));
                $second = new Paragraph();
                $second->appendChild(new Text('B'));
                $parent->appendChild($first);
                $parent->appendChild($second);

                return 1;
            },
        );

        // A legacy callback that appends several sibling blocks keeps them flat —
        // they must NOT be collapsed into a synthetic wrapper <div>.
        $this->assertSame("<p>A</p>\n<p>B</p>", trim($converter->convert('DUO')));
    }
}
