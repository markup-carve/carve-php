<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerFenceLookaheadTest extends TestCase
{
    protected function html(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }

    public function testFenceDecisionSurvivesTheItemBoundaryItCreates(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>\n<p>y\n<code></code></p>",
            $this->html("- a\n  ```\n  b\n y\n  ```\n"),
        );
    }

    #[DataProvider('invalidCloserColumns')]
    public function testFenceCloserMustBeAtTheItemContentColumn(string $closer): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n<code>\nb\ny\n</code></li>\n</ul>",
            $this->html("- a\n  ```\n  b\n y\n{$closer}\n"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCloserColumns(): iterable
    {
        yield 'flush left' => ['```'];
        yield 'one column short' => [' ```'];
        yield 'one column past' => ['   ```'];
    }

    #[DataProvider('invalidDefinitionCloserColumns')]
    public function testFenceCloserMustBeAtTheDefinitionBodyColumn(string $closer): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny\n</code></dd>\n</dl>",
            $this->html(":: t\n: a\n  ```\n  b\n y\n{$closer}\n"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDefinitionCloserColumns(): iterable
    {
        yield 'flush left' => ['```'];
        yield 'one column past' => ['   ```'];
    }

    #[DataProvider('lazyColonOpeners')]
    public function testLazyMarkerLineColonOpenerDoesNotClaimAContentColumnRun(string $source, string $list): void
    {
        $this->assertSame(
            "<{$list}>\n  <li>:::\ny\n    <div>\n\n    </div>\n  </li>\n</{$list}>",
            $this->html($source),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lazyColonOpeners(): iterable
    {
        yield 'bullet item' => ["- :::\n y\n  :::\n", 'ul'];
        yield 'ordered item' => ["1. :::\n  y\n   :::\n", 'ol'];
    }
}
