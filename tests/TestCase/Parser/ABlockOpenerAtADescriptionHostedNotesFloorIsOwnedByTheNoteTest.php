<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block opener at a description-hosted note's floor is owned by the note.
 *
 * Ruled on markup-carve/carve#1974. In a description body (`dd`) a block opener
 * at or past a hosted note's floor - the note marker column plus two, PART 9
 * §16 - REACHES the note body, exactly as a plain continuation line does. An
 * unreferenced note then drops together with the opener it took.
 *
 * The `dd` content column is 3, so the lead note `[^f]` stands at 3 and its
 * body floor is 5. The list opener sits at 5, reaches the note, and `[^f]` is
 * never referenced, so the note and its list drop and the `dd` is left empty.
 *
 * Two answers are combined and each is the maintainer's call:
 *   - Q1, does the opener reach the note body: yes, matching carve-js
 *     `6b050a68` and `@djot/djot`, which read the run as the note's body.
 *   - Q2, where the trailing line lands: carve-php's existing answer, left
 *     untouched by the ruling. At column 0 `tail` is below the `dd`'s base and
 *     PART 0's owner-selection table gives it to the document; at the `dd`
 *     content column it continues the `dd`. carve-js keeps `tail` in the `dd`
 *     at column 0; that half is not this ruling and is not adopted.
 */
class ABlockOpenerAtADescriptionHostedNotesFloorIsOwnedByTheNoteTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The four cells the ticket names, each measured against the ruling.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sweepProvider(): array
    {
        return [
            // Opener at the floor, trailing line flush left: the note takes the
            // list and drops, and `tail` is a document paragraph.
            'opener at the floor, tail at column 0' => [
                ":: t\n:  [^f]: note\n     - nested\ntail\n",
                "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>tail</p>",
            ],
            // Opener at the floor, trailing line at the `dd` content column: the
            // list is still absorbed and dropped, and `tail` continues the `dd`.
            'opener at the floor, tail at the dd column' => [
                ":: t\n:  [^f]: note\n     - nested\n   tail\n",
                "<dl>\n  <dt>t</dt>\n  <dd>tail</dd>\n</dl>",
            ],
            // Opener one column shy of the floor: below the note body, so the
            // list is a `dd` child and `tail` lazily continues the item.
            'opener one shy of the floor' => [
                ":: t\n:  [^f]: note\n    - nested\ntail\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>nested\ntail</li>\n    </ul>\n  </dd>\n</dl>",
            ],
            // Continuation TEXT at the floor: this engine already gave it to the
            // note; the opener now matches it, which is the self-consistency
            // carve#1974 restores.
            'continuation text at the floor' => [
                ":: t\n:  [^f]: note\n     text\ntail\n",
                "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>tail</p>",
            ],
        ];
    }

    #[DataProvider('sweepProvider')]
    public function testTheSweep(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }

    /**
     * THE OPENER READS LIKE THE TEXT. The floor test is a claim about the
     * opener matching a plain continuation line, so the two must produce the
     * same document byte for byte at the floor - flush-left `tail` and
     * `dd`-column `tail` alike.
     *
     * @return array<string, array{0: string}>
     */
    public static function tailColumnProvider(): array
    {
        return [
            'tail at column 0' => ["tail\n"],
            'tail at the dd column' => ["   tail\n"],
        ];
    }

    #[DataProvider('tailColumnProvider')]
    public function testAnOpenerAtTheFloorReadsLikeTextAtTheFloor(string $tail): void
    {
        $opener = $this->converter->convert(":: t\n:  [^f]: note\n     - nested\n" . $tail);
        $text = $this->converter->convert(":: t\n:  [^f]: note\n     text\n" . $tail);

        $this->assertSame($text, $opener);
    }
}
