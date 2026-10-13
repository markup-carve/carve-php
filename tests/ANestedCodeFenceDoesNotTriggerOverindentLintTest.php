<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\TestCase;

class ANestedCodeFenceDoesNotTriggerOverindentLintTest extends TestCase
{
    public function testCorpusRow581Four(): void
    {
        $source = "> - a\n>     - child\n>     ```\n>\n>       ```\n>   ~~~\nafter\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<li>child\n<code></code>\n          <pre><code></code></pre>", $html);
        self::assertStringContainsString("      <pre><code></code></pre>", $html);
        self::assertNotContains('list-item-block-overindented', array_column((new SourceLinter())->lint($source), 'rule'));
    }
}
