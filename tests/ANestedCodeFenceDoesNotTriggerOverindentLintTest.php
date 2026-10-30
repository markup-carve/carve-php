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

        self::assertStringContainsString("<li>child</li>\n      </ul>\n      <pre><code>\n  ```\n~~~\n</code></pre>", $html);
        self::assertStringContainsString("</blockquote>\n<p>after</p>", $html);
        // The fence now opens in the outer item past its content column, as carve-js reports.
        self::assertContains('list-item-block-overindented', array_column((new SourceLinter())->lint($source), 'rule'));
    }
}
