<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

class TocPlacementExtensionTest extends TestCase
{
    private function html(string $source): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());

        return $converter->convert($source);
    }

    public function testRendersNestedNavAtTheDirective(): void
    {
        // Directive at column 0 (before the headings) so the emitted list is
        // not context-indented: byte-identical to carve-js / TableOfContentsExtension.
        $out = $this->html("::: toc\n:::\n\n# Intro\n\n## Setup\n\n### Details\n\n## Usage\n");
        $this->assertStringContainsString(
            "<nav class=\"toc\">\n<ul>\n<li><a href=\"#Intro\">Intro</a>\n<ul>\n"
                . "<li><a href=\"#Setup\">Setup</a>\n<ul>\n<li><a href=\"#Details\">Details</a></li>\n</ul>\n</li>\n"
                . "<li><a href=\"#Usage\">Usage</a></li>\n</ul>\n</li>\n</ul>\n</nav>",
            $out,
        );
        // Placed before the sections.
        $this->assertLessThan(strpos($out, '<h1'), strpos($out, '<nav class="toc"'));
    }

    public function testLinksToResolvedDedupAwareIds(): void
    {
        $out = $this->html("# Intro\n\n## Intro\n\n::: toc\n:::\n");
        preg_match_all('/<section id="([^"]+)"/', $out, $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $id) {
            $this->assertStringContainsString('<a href="#' . $id . '">Intro</a>', $out);
        }
    }

    public function testDepthLimitsToLevels(): void
    {
        $out = $this->html("# A\n\n{depth=2}\n::: toc\n:::\n\n## B\n\n### C\n\n## D\n");
        $this->assertStringContainsString('<a href="#B">B</a>', $out);
        $this->assertStringContainsString('<a href="#D">D</a>', $out);
        $this->assertStringNotContainsString('href="#C"', $out);
    }

    public function testFromToWindow(): void
    {
        $out = $this->html("# A\n\n{from=2 to=2}\n::: toc\n:::\n\n## B\n\n### C\n\n## D\n");
        $this->assertStringContainsString('<a href="#B">B</a>', $out);
        $this->assertStringContainsString('<a href="#D">D</a>', $out);
        $this->assertStringNotContainsString('href="#A"', $out);
        $this->assertStringNotContainsString('href="#C"', $out);
    }

    public function testCarriesAuthorAttrsAndStripsWindowKeys(): void
    {
        $out = $this->html("# A\n\n{#nav .side depth=1}\n::: toc\n:::\n\n## B\n");
        $this->assertStringContainsString('<nav id="nav" class="toc side">', $out);
        $this->assertStringNotContainsString('depth=', $out);
    }

    public function testInvertedWindowIsSwapped(): void
    {
        $out = $this->html("# A\n\n{from=3 to=1}\n::: toc\n:::\n\n## B\n\n### C\n");
        $this->assertStringContainsString('href="#A"', $out);
        $this->assertStringContainsString('href="#B"', $out);
        $this->assertStringContainsString('href="#C"', $out);
    }

    public function testEmptyWindowRendersEmptyNav(): void
    {
        $out = $this->html("::: toc\n:::\n\nplain paragraph\n");
        $this->assertStringContainsString('<nav class="toc"></nav>', $out);
    }

    public function testInertWithoutExtension(): void
    {
        $converter = new CarveConverter();
        $out = $converter->convert("# A\n\n::: toc\n:::\n");
        $this->assertStringContainsString('class="toc"', $out);
        $this->assertStringNotContainsString('<nav', $out);
    }

    public function testIncludesNestedContainerHeadings(): void
    {
        // Headings inside ::: note and blockquotes render with id anchors, so
        // the placed TOC includes them.
        $out = $this->html("::: toc\n:::\n\n# Top\n\n::: note\n## InNote\n:::\n\n> ## InQuote\n");
        $this->assertStringContainsString('<a href="#InNote">InNote</a>', $out);
        $this->assertStringContainsString('<a href="#InQuote">InQuote</a>', $out);
    }

    public function testNestsDeeperHeadingUnderShallowerPredecessor(): void
    {
        // # A / ### B / ## C / ### D: D must nest under C, not flatten under it.
        $out = $this->html("::: toc\n:::\n\n# A\n\n### B\n\n## C\n\n### D\n");
        $this->assertStringContainsString("C</a>\n<ul>\n<li><a href=\"#D\">D</a></li>", $out);
    }

    public function testStripsBidiControlsFromTocText(): void
    {
        $out = $this->html("::: toc\n:::\n\n# A\u{202E}evil\n");
        $nav = substr($out, 0, (int)strpos($out, '</nav>'));
        $this->assertStringNotContainsString("\u{202E}", $nav);
    }

    public function testBoundsAmplificationFromManyTocBlocks(): void
    {
        $doc = '';
        for ($i = 0; $i < 50; $i++) {
            $doc .= "# Heading number $i with length\n\n";
        }
        $blocks = str_repeat("::: toc\n:::\n\n", 5000);
        $src = $blocks . $doc;
        $out = $this->html($src);
        $this->assertLessThan((int)(max(1000000, 8 * strlen($src)) * 1.3), strlen($out));
    }
}
