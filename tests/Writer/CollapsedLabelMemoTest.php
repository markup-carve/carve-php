<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6052: the reads of collapsed labels are kept across the writes of one `fmt`, and the resolver reads a
 * collapsed key only to meet a heading.
 */
final class CollapsedLabelMemoTest extends TestCase
{
    public function testWritesWithTheSameLabelReadBackInTurn(): void
    {
        $sources = [
            "[a *b*][] and [a *b*]\n",
            "[a \\*b\\*][] and [a *b*][]\n",
            "[a  *b*][]\n\n[a *b*]: /u\n",
            "[a *b*][] and [a *b*]\n",
        ];
        $written = [];
        foreach ($sources as $source) {
            $output = Carve::format($source);
            $written[] = $output;
            $this->assertTrue(TreeEquality::equal(self::tree($source), self::tree($output)), $source);
        }
        $this->assertSame($written[0], $written[3]);
    }

    public function testACollapsedKeyStillMeetsAHeading(): void
    {
        $this->assertStringContainsString('<a href="#Intro">Intro</a>', Carve::render("# Intro\n\nSee [Intro][].\n"));
        $this->assertStringContainsString('See [Intro][].', Carve::render("See [Intro][].\n"));
    }

    /**
     * @return array<string, mixed>
     */
    private static function tree(string $source): array
    {
        return TreeEncoder::decoded(Carve::parse($source));
    }
}
