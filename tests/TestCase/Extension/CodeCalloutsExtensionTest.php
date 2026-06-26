<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\CodeCalloutsExtension;
use PHPUnit\Framework\TestCase;

class CodeCalloutsExtensionTest extends TestCase
{
    private function html(string $source): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CodeCalloutsExtension());

        return trim($converter->convert($source));
    }

    /**
     * @var string
     */
    private const SRC = "```js\nconst x = compute();   <1>\nreturn x * 2;          <2>\n```\n\n<1> Runs the expensive step once.\n<2> Doubles the result.";

    public function testInCodeMarkersRenderAsBubbles(): void
    {
        $out = $this->html(self::SRC);
        $this->assertStringContainsString(
            'const x = compute();   <b class="callout" data-callout="1">1</b>',
            $out,
        );
        $this->assertStringContainsString(
            'return x * 2;          <b class="callout" data-callout="2">2</b>',
            $out,
        );
    }

    public function testBindsFollowingListWithExplicitValues(): void
    {
        $out = $this->html(self::SRC);
        $this->assertStringContainsString('<ol class="callouts">', $out);
        $this->assertStringContainsString('<li value="1">Runs the expensive step once.</li>', $out);
        $this->assertStringContainsString('<li value="2">Doubles the result.</li>', $out);
    }

    public function testNonSequentialMarkerPreserved(): void
    {
        $out = $this->html("```\nfoo()  <3>\n```\n\n<3> only three.");
        $this->assertStringContainsString('data-callout="3">3</b>', $out);
        $this->assertStringContainsString('<li value="3">only three.</li>', $out);
    }

    public function testEscapesCodeAroundMarker(): void
    {
        $out = $this->html("```\na < b && c;  <1>\n```\n\n<1> note.");
        $this->assertStringContainsString(
            'a &lt; b &amp;&amp; c;  <b class="callout" data-callout="1">1</b>',
            $out,
        );
    }

    public function testNoMarkerDoesNotBindList(): void
    {
        $out = $this->html("```\nplain();\n```\n\n<1> orphan.");
        $this->assertStringNotContainsString('class="callouts"', $out);
        $this->assertStringContainsString('&lt;1&gt; orphan.', $out);
    }

    public function testNonItemLineDoesNotBind(): void
    {
        $out = $this->html("```\nfoo()  <1>\n```\n\n<1> first.\nnot a callout line.");
        $this->assertStringNotContainsString('class="callouts"', $out);
        $this->assertStringContainsString('data-callout="1">1</b>', $out); // marker independent of list
    }

    public function testAuthoredAttributesOnList(): void
    {
        $out = $this->html("```\nfoo()  <1>\n```\n\n{#notes .wide}\n<1> note.");
        $this->assertStringContainsString('<ol id="notes" class="callouts wide">', $out);
    }

    public function testDoesNotCrashOnDefinitionList(): void
    {
        $out = $this->html(":: term\n:  a definition\n\n```\nx  <1>\n```\n\n<1> note.");
        $this->assertStringContainsString('<dl>', $out);
        $this->assertStringContainsString('data-callout="1">1</b>', $out);
    }

    public function testOffLeavesMarkersLiteral(): void
    {
        $out = trim((new CarveConverter())->convert(self::SRC));
        $this->assertStringContainsString('&lt;1&gt;', $out);
        $this->assertStringNotContainsString('class="callout', $out);
        $this->assertStringContainsString('<p>&lt;1&gt; Runs the expensive step once.', $out);
    }

    public function testOnlyTrailingMarkerCounts(): void
    {
        $out = $this->html("```\nVec<2> v;  <1>\n```\n\n<1> note.");
        $this->assertStringContainsString(
            'Vec&lt;2&gt; v;  <b class="callout" data-callout="1">1</b>',
            $out,
        );
    }
}
