<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class TaskListHtmlHooksTest extends TestCase
{
    public function testNestedPlainListsDoNotInheritTaskHooks(): void
    {
        $converter = new CarveConverter();
        $source = "- [ ] open\n- [X] done\n  - child\n";
        $html = $converter->convert($source);
        $this->assertSame(1, substr_count($html, 'class="task-list"'));
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li data-task-state="x">', $html);
        $this->assertStringContainsString('<li><input type="checkbox" disabled', $html);
        $renderer = new CarveRenderer();
        $this->assertSame(
            $renderer->render($converter->parse($source)),
            $renderer->render($converter->parse((new HtmlToCarve())->convert($html))),
        );
    }

    public function testTheBaseClassDeduplicatesAndKeepsItsAuthoredSlot(): void
    {
        $html = (new CarveConverter())->convert("{#tasks .task-list .c}\n- [x] done\n");
        $this->assertStringContainsString('<ul id="tasks" class="task-list c">', $html);
    }

    public function testUppercaseClassKeepsItsAuthoredSlot(): void
    {
        $html = (new CarveConverter())->convert("{#tasks CLASS=foo}\n- [ ] open\n");
        $this->assertStringContainsString('<ul id="tasks" CLASS="task-list foo">', $html);
        $this->assertSame(1, substr_count(strtolower($html), 'class='));
    }

    public function testNestedTasksDoNotClassifyTheirPlainParent(): void
    {
        $html = (new CarveConverter())->convert("- parent\n  - [x] child\n");
        $this->assertSame(1, substr_count($html, 'class="task-list"'));
        $this->assertStringStartsWith('<ul>', $html);
        $this->assertStringNotContainsString('task-list', (new CarveConverter())->convert("1. plain\n"));
    }

    public function testCheckedHooksAreConsumedWithoutAnExtendedState(): void
    {
        $converter = new CarveConverter();
        $html = '<ul class="task-list c"><li data-task-state="x"><input type="checkbox" checked disabled> done</li></ul>';
        $source = (new HtmlToCarve())->convert($html);
        $this->assertStringNotContainsString('task-list', $source);
        $this->assertStringNotContainsString('data-task-state', $source);
        $this->assertStringContainsString('[x] done', $source);
        $list = $converter->parse($source)->getChildren()[0];
        $this->assertNull($list->getChildren()[0]->getAuthoredTaskState());
    }

    public function testPlainListClassesRemainAuthored(): void
    {
        $source = (new HtmlToCarve())->convert('<ul class="task-list"><li>plain</li></ul>');
        $this->assertStringContainsString('.task-list', $source);
    }

    public function testCheckedHooksSuppressCaseInsensitiveAttributeCollisions(): void
    {
        $html = (new CarveConverter())->convert("-{DATA-TASK-STATE=?} [x] done\n");
        $this->assertSame(1, substr_count(strtolower($html), 'data-task-state='));
        $this->assertStringContainsString('data-task-state="x"', $html);
    }

    public function testEveryExtendedStateSurvivesHtmlImport(): void
    {
        $converter = new CarveConverter();
        $renderer = new CarveRenderer();
        foreach (['-', '_', '>', '?'] as $state) {
            $source = "- [$state] task\n";
            $imported = (new HtmlToCarve())->convert($converter->convert($source));
            $this->assertSame($source, $renderer->render($converter->parse($imported)));
        }
    }

    public function testContradictoryExtendedStatesRemainAuthored(): void
    {
        $source = (new HtmlToCarve())->convert('<ul><li data-task-state="_"><input type="checkbox" checked disabled> done</li></ul>');
        $this->assertStringContainsString('data-task-state', $source);
        $this->assertStringContainsString('[x] done', $source);
    }
}
