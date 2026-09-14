<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description body whose only content is an unreferenced footnote definition
 * that absorbs its own body renders an EMPTY `dd`, and a column-0 line below is a
 * document sibling (carve#1974). The writer emits the collected note back on the
 * description line, but its body's continuation lines were left at the note's
 * standalone column - one marker short of the description body they now sit in.
 * On re-parse that line fell out of the note, so the description stopped being
 * empty and `fmt` was both lossy and non-idempotent (carve#1980). Each shape's
 * canonical form is byte-identical to carve-js, the round-trip reference.
 */
class TheFormatterRoundTripsADescriptionWhoseOnlyContentIsADroppedNoteTest extends TestCase
{
    protected function html(string $source): string
    {
        return trim(CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{source: string, canonical: string}>
     */
    public static function droppedNoteShapes(): array
    {
        return [
            'note body is an indented list marker' => [
                'source' => ":: t\n:  [^f]: note\n     - nested\ntail\n",
                'canonical' => ":: t\n: [^f]: note\n    - nested\n\ntail\n",
            ],
            'note body is a plain continuation line' => [
                'source' => ":: t\n:  [^f]: note\n     cont\ntail\n",
                'canonical' => ":: t\n: [^f]: note\n    cont\n\ntail\n",
            ],
        ];
    }

    /**
     * The absorbed note leaves the `dd` empty and the column-0 line is a sibling;
     * the canonical form matches carve-js (the note body's continuation sits at
     * column 4, the description body column plus the note's own two); and it is
     * both semantically lossless and idempotent.
     */
    #[DataProvider('droppedNoteShapes')]
    public function testTheDroppedNoteShapeRoundTrips(string $source, string $canonical): void
    {
        $html = $this->html($source);
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>tail</p>", $html);

        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($canonical, $formatted, 'canonical form matches the carve-js reference');
        $this->assertSame($html, $this->html($formatted), 'formatting is semantically lossless');
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'formatting is idempotent');
    }
}
