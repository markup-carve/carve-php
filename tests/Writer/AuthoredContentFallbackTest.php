<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6127, C2-W-088: past the depth cap the rounds stop and the paragraph takes its authored content, written
 * behind the writer's own line prefixes, as carve-js writes it: a lazy line gets `> `, an indented line loses its indent.
 */
final class AuthoredContentFallbackTest extends TestCase
{
    /**
     * @var string
     */
    private const LINE = '[a *[a][]*';

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function provideShapes(): array
    {
        $quoted = '> ' . self::LINE;
        $lazy = implode("\n", [$quoted, '*[{*', ...array_fill(0, 104, $quoted), '*', ...array_fill(0, 91, $quoted)]) . "\n";
        $lazyWritten = implode("\n", [$quoted, '> *[{*', ...array_fill(0, 104, $quoted), '> *', ...array_fill(0, 91, $quoted)]) . "\n";
        $indented = '  ' . implode("\n  ", [self::LINE, '*[{*', ...array_fill(0, 198, self::LINE)]) . "\n";
        $indentedWritten = implode("\n", [self::LINE, '*[{*', ...array_fill(0, 198, self::LINE)]) . "\n";

        return [
            'lazy quote lines' => [$lazy, $lazyWritten, false],
            'lazy quote lines, normalize' => [$lazy, $lazyWritten, true],
            'indented lines' => [$indented, $indentedWritten, false],
            'indented lines, normalize' => [$indented, $indentedWritten, true],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testAuthoredContentTakesTheWrittenPrefixes(string $source, string $expected, bool $normalize): void
    {
        $result = Carve::formatWithDiagnostics($source, $normalize ? ['normalize' => true] : []);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($expected, $result->source);
    }
}
