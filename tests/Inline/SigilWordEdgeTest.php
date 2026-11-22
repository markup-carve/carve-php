<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-I-092: a name run glued to a letter, number or mark outside ASCII starts no form.
 */
final class SigilWordEdgeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function renders(): array
    {
        return [
            'underscore before a letter' => ['#a_é', '<p>#a_é</p>'],
            'double dot before a letter' => ['@a..é', '<p>@a..é</p>'],
            'mark after a dot' => ["#a.\u{0301}", "<p>#a.\u{0301}</p>"],
            'trailing hyphen before a space' => ['#a- é', '<p><span class="tag"><strong>#a</strong></span>- é</p>'],
            'symbol after the run' => ['#a-€', '<p><span class="tag"><strong>#a</strong></span>-€</p>'],
        ];
    }

    #[DataProvider('renders')]
    public function testRender(string $source, string $html): void
    {
        self::assertSame($html, Carve::toHtml($source));
    }

    public function testWriterEscapesTheNameCharacterThatWouldEndTheForm(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'tag', 'name' => 'a'],
                        ['type' => 'text', 'value' => '_é'],
                    ],
                ],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);

        self::assertSame("#a\\_é\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testFormatKeepsATextSigilBare(): void
    {
        self::assertSame("#a_é and @b..é\n", Carve::format("#a_é and @b..é\n"));
    }
}
