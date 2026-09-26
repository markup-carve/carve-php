<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` one column left of an in-item quote's marker is ordinary text.
 *
 * carve-php#2470. `CARVE-P9-031` places the continuation marker at its
 * container's MARKER COLUMN and nowhere else, and column 1 under `- x` /
 * ` > q` names no container: the list's marker sits at 0, the quote's at 2.
 * The quote's own continuation site read no column, because the item
 * collector had already stripped the line to a bare `+` - the same spelling a
 * marker at the quote's column arrives in - so the marker was consumed and the
 * line vanished. It now falls through to the lazy fold, as carve-js
 * `1606df3` does.
 */
class AMarkerLeftOfAQuotesColumnIsTextTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    protected function html(string $source): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $this->converter->convert($source)));
    }

    /**
     * Every column the `+` can take, which is what says the fix moved one of
     * them. Columns 0 and 2 are the two marker columns in play and keep
     * consuming the marker; 3 and 4 were already text.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function columnProvider(): array
    {
        return [
            'column 0, the list marker column' => [0, false],
            'column 1, no container' => [1, true],
            'column 2, the quote marker column' => [2, false],
            'column 3, inside the quote content' => [3, true],
            'column 4' => [4, true],
        ];
    }

    #[DataProvider('columnProvider')]
    public function testTheMarkerSurvivesOnlyOffAMarkerColumn(int $column, bool $kept): void
    {
        $html = $this->html("- x\n  > q\n" . str_repeat(' ', $column) . "+\n");

        $this->assertSame(
            $kept ? '<ul> <li>x <blockquote><p>q +</p></blockquote> </li> </ul>'
                : '<ul> <li>x <blockquote><p>q</p></blockquote> </li> </ul>',
            $html,
        );
    }

    /**
     * A top-level quote's own marker still ATTACHES a flush-left block, the
     * behavior the column gate must not have taken away.
     */
    public function testTheQuotesOwnMarkerStillAttachesABlock(): void
    {
        $this->assertSame(
            '<blockquote> <p>q</p> <ul> <li>m</li> </ul> </blockquote>',
            $this->html("> q\n+\n- m\n"),
        );
    }

    /**
     * And one column right of it is text there too, the top-level twin of the
     * shape this fixes.
     */
    public function testAnIndentedMarkerOnATopLevelQuoteIsText(): void
    {
        $this->assertSame(
            '<blockquote><p>q + - m</p></blockquote>',
            $this->html("> q\n +\n- m\n"),
        );
    }
}
