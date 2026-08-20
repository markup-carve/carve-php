<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ParseWarning;
use PHPUnit\Framework\TestCase;

class IntegratedDefinitionPassTest extends TestCase
{
    public function testForwardDefinitionsResolveInOneStructuralWalk(): void
    {
        $source = "HTML [link][r] note[^n].\n\n[r]: /u\n\n[^n]: body\n\n*[HTML]: HyperText\n";
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString('<abbr title="HyperText">HTML</abbr>', $html);
        $this->assertStringContainsString('<a href="/u">link</a>', $html);
        $this->assertStringContainsString('role="doc-noteref"', $html);
    }

    public function testAuthoredReferenceAttributesOverrideDefinitionAttributes(): void
    {
        $source = "[x][r]{#local .own}\n\n[r]: /u {.def key=v #remote}\n\n[^n]: note\n";

        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<a href="/u"', $html);
        $this->assertStringContainsString('class="def own"', $html);
        $this->assertStringContainsString('key="v"', $html);
        $this->assertStringContainsString('id="local"', $html);
        $this->assertStringNotContainsString('remote', $html);
    }

    public function testForwardImageReceivesItsDefinition(): void
    {
        $source = "![alt][image]\n\n[image]: /image.png \"Title\"\n\n[^n]: note\n";

        $this->assertStringContainsString(
            '<img src="/image.png" alt="alt" title="Title">',
            (new CarveConverter())->convert($source),
        );
    }

    public function testOnlyWarningsThatResolveLaterAreRemoved(): void
    {
        $converter = new CarveConverter(warnings: true);
        $converter->convert("[ok][r] [bad][missing] [^n] [^missing]\n\n[r]: /u\n\n[^n]: note\n");
        $messages = array_map(static fn (ParseWarning $warning): string => $warning->getMessage(), $converter->getWarnings());

        $this->assertContains("Undefined reference 'missing'", $messages);
        $this->assertContains("Undefined footnote 'missing'", $messages);
        $this->assertNotContains("Undefined reference 'r'", $messages);
        $this->assertNotContains("Undefined footnote 'n'", $messages);
    }

    public function testDedentingOutOfAnUnterminatedListFenceReenablesDefinitions(): void
    {
        $source = "- ```\n  x\n\nSee[^n]\n\n[^n]: note\n\n[r]: /u\n";
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString('role="doc-noteref"', $html);
        $this->assertStringContainsString('<p>note', $html);
    }
}
