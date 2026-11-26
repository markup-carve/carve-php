<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class Next2FormatCaseTest extends TestCase
{
    public function testQuotedFenceTitleKeepsEscapedQuote(): void
    {
        $source = "```js \"a\\\"b\"\nx\n```\n";
        $formatted = Carve::formatWithDiagnostics($source);
        self::assertSame($source, $formatted->source);
        self::assertSame([], $formatted->diagnostics);
    }
}
