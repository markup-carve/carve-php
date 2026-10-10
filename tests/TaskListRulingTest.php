<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownAssessment;
use PHPUnit\Framework\TestCase;

class TaskListRulingTest extends TestCase
{
    public function testStatesRenderAndImportInEveryMode(): void
    {
        $source = "- [ ] open\n- [x] done\n- [X] shout\n- [-] dropped\n- [_] paused\n- [>] deferred\n- [?] maybe\n";
        foreach ([false, true] as $roundTrip) {
            foreach (['interactive', 'static'] as $mode) {
                $converter = new CarveConverter(roundTripMode: $roundTrip, mode: $mode);
                $html = $converter->convert($source);
                $this->assertStringStartsWith('<ul class="task-list">', $html);
                $this->assertStringContainsString('<li><input type="checkbox" disabled aria-label="open">', $html);
                $this->assertSame(2, substr_count($html, 'data-task-state="x"'));
                foreach (['-', '_', '&gt;', '?'] as $state) {
                    $this->assertStringContainsString('data-task-state="' . $state . '"', $html);
                }
                $this->assertSame(str_replace('[X]', '[x]', $source), (new HtmlToCarve())->convert($html));
            }
        }
    }

    public function testClassAndAttributeOrdering(): void
    {
        foreach ([false, true] as $roundTrip) {
            $converter = new CarveConverter(roundTripMode: $roundTrip);
            $this->assertStringStartsWith('<ul id="tasks" class="task-list c">', $converter->convert("{#tasks .c}\n- [ ] open\n"));
            $this->assertStringStartsWith('<ul class="task-list"', $converter->convert("{#tasks}\n- [ ] open\n"));
            $html = $converter->convert("{.c}\n- [x] done\n");
            $this->assertSame("{.c}\n- [x] done\n", (new HtmlToCarve())->convert($html));
        }
    }

    public function testPlainNestedAndOrderedListsStayPlain(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("- [ ] outer\n  - inner\n- plain\n\n1. [ ] text\n");
        $this->assertSame(1, substr_count($html, 'class="task-list"'));
        $this->assertStringContainsString("    <ul>\n      <li>inner</li>", $html);
        $this->assertStringContainsString("<ul>\n  <li>plain</li>", $html);
        $this->assertStringContainsString('<li>[ ] text</li>', $html);
    }

    public function testImporterConsumesStructuralClassAndCheckedState(): void
    {
        $importer = new HtmlToCarve();
        $this->assertSame("{.c}\n- [x] done\n", $importer->convert('<ul class="task-list c"><li data-task-state="x"><input type="checkbox" checked>done</li></ul>'));
        $this->assertSame("-{data-task-state=x} [ ] open\n", $importer->convert('<ul class="task-list"><li data-task-state="x"><input type="checkbox">open</li></ul>'));
    }

    public function testMarkdownAssessmentAgreesWithTaskHtml(): void
    {
        $source = "- [ ] open\n- [x] done\n- plain\n";
        $result = (new MarkdownAssessment())->assess($source, $source);
        $this->assertFalse($result['complete']);
        $tasks = "- [ ] open\n- [x] done\n";
        $this->assertTrue((new MarkdownAssessment())->assess($tasks, $tasks)['complete']);
    }
}
