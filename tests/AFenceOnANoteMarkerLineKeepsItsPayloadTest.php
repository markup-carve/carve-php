<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-083: a marker-line note's fence is read at the note's body column, so a run
 * indented past it is payload and a later `%%` line stays code (carve#6012).
 */
class AFenceOnANoteMarkerLineKeepsItsPayloadTest extends TestCase
{
    #[DataProvider('payloads')]
    public function testOverIndentedRunStaysPayload(string $source, string $code): void
    {
        self::assertStringContainsString($code, CarveConverter::create()->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function payloads(): iterable
    {
        yield 'backtick fence' => ["x[^n]\n\n[^n]: ```\n   ```\n   %%\n", "<pre><code> ```\n %%\n</code></pre>"];
        yield 'tilde fence' => ["x[^n]\n\n[^n]: ~~~\n   ~~~\n   %%\n", "<pre><code> ~~~\n %%\n</code></pre>"];
        yield 'list item, payload first' => ["x[^n]\n\n- [^n]: ```\n     c\n     ```\n     %%\n", "<pre><code> c\n ```\n %%\n</code></pre>"];
        yield 'list item, run first' => ["x[^n]\n\n- [^n]: ```\n     ```\n     %% x\n", "<pre><code> ```\n %% x\n</code></pre>"];
        yield 'list item, text after run' => ["x[^n]\n\n- [^n]: ```\n     ```\n     x\n", "<pre><code> ```\n x\n</code></pre>"];
    }

    public function testRunAtTheBodyColumnStillCloses(): void
    {
        self::assertStringContainsString(
            "<pre><code>c\n</code></pre>",
            CarveConverter::create()->convert("x[^n]\n\n[^n]: ```\n  c\n  ```\n  %%\n"),
        );
    }
}
