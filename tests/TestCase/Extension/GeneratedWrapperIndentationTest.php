<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\TestCase;

class GeneratedWrapperIndentationTest extends TestCase
{
    public function testInteractiveInteriorsStayAtColumnZeroAtEveryDepth(): void
    {
        foreach (['css', 'aria'] as $mode) {
            foreach (['code-group', 'tabs'] as $kind) {
                $converter = new CarveConverter();
                $converter->addExtension($kind === 'tabs' ? new TabsExtension(mode: $mode) : new CodeGroupExtension(mode: $mode));
                $source = $kind === 'tabs'
                    ? "::: tabs\n:::: tab [First]\nContent one.\n::::\n\n:::: tab [Second]\nContent two.\n::::\n:::\n"
                    : "::: code-group\n```js\nx\n```\n:::\n";
                $top = $converter->convert($source);
                foreach ([1, 2] as $depth) {
                    $nested = $source;
                    for ($level = 0; $level < $depth; $level++) {
                        $fence = str_repeat(':', 5 + $level);
                        $nested = "$fence wrap\n$nested$fence\n";
                    }
                    $html = $converter->convert($nested);
                    $start = strpos($html, '<div class="' . $kind . '"');
                    $this->assertNotFalse($start);
                    $suffix = str_repeat(' ', $depth * 2) . "</div>\n";
                    $end = strrpos($html, $suffix);
                    $this->assertNotFalse($end);
                    $fragment = substr($html, $start, $end - $start) . "</div>\n";
                    $this->assertSame($top, $fragment, "$mode $kind at depth $depth");
                }
            }
        }
    }
}
