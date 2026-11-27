<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-117 and C2-B-122: select a message from parsed opener parts.
 */
final class FenceTitleSyntax6137Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function openers(): array
    {
        $further = 'These further words are not read as a title, label or attributes. The block opens without them.';

        return [
            'curly title without language' => ["``` “Title”\nx\n```", $further . ' Use "```"'],
            'tab inside title' => ["```js \"foo\tbar\" junk\nx\n```", 'These words follow a valid title'],
            'tab inside label' => ["```js [foo\tbar] junk\nx\n```", $further . ' Use "``` js [foo' . "\t" . 'bar]"'],
            'spaced brace block' => ["```  {a=1}\nx\n```", $further . ' Use "```"'],
            'tab then space before brace' => ["```\t {.a}\nx\n```", 'The whitespace in this fence opener is invalid'],
        ];
    }

    #[DataProvider('openers')]
    public function testMessageUsesParsedOpenerParts(string $source, string $expected): void
    {
        $found = array_values(array_filter(Carve::lint($source), static fn (Diagnostic $d): bool => $d->code === 'fence-title-syntax'));

        self::assertCount(1, $found);
        self::assertStringContainsString($expected, $found[0]->message);
    }
}
