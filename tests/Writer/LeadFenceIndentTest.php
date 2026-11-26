<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6440, C2-B-029, C2-W-033: a fence opened behind a task box, a footnote label or glued attributes strips up to
 * its opener's indent from each line, so a value line that starts with whitespace is written at the opener's column,
 * and the closer with it.
 */
final class LeadFenceIndentTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function cases(): array
    {
        return [
            'task box' => ["- [x] ```\n        y\n      ```\n"],
            'task box, blank line' => ["- [ ] ```js\n        y\n\n      z\n      ```\n"],
            'bare dot task box' => [". [x] ```\n          y\n      ```\n"],
            'glued attributes' => ["-{.a} ```\n        y\n      ```\n"],
            'footnote label' => ["[^n]: ```\n        y\n      ```\n"],
            'nested item' => ["- - [x] ```\n          y\n        ```\n"],
            'item in a quote' => ["> - [x] ```\n>         y\n>       ```\n"],
            'quote in a footnote' => ["[^n]: > - [x] ```\n  >         y\n  >       ```\n"],
            'raw block' => ["- [x] ```=html\n        <b>\n      ```\n"],
            'comment block' => ["- [x] %%%\n        y\n      %%%\n"],
            // C2-W-024: without whitespace at the start of a line, the lines stand at the content column.
            'no leading whitespace' => ["- [x] ```\n  y\n  ```\n"],
            'no task box' => ["- ```\n      y\n  ```\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testWritesBackUnchanged(string $input): void
    {
        $sidecar = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
        $this->assertSame($input, Carve::format($input, ['normalize' => true]));
        $bare = Carve::writeWithDiagnostics(Carve::parse($input));
        $this->assertSame($input, $bare->source);
        $this->assertSame([], $bare->diagnostics);
    }
}
