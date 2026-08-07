<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference definition's two metadata slots take exactly one space.
 *
 * `reference_definition = '[', reference_label, ']', ':', space,
 * link_destination, [link_title], [space, attributes], newline` carries two of
 * the four slots PART 7 holds to exactly one space (markup-carve/carve#912):
 * the one `link_title` opens with, and the one before the trailing attribute
 * block. Both read a RUN in this engine - the title through a Unicode
 * whitespace class, the attribute block through a "the character before the
 * brace is a space or a tab" test - so `[a]: /u<SP><SP>"T"` carried a title and
 * `[a]: /u<SP><SP>{.c}` attributed the definition.
 *
 * TWO SLOTS, TWO EXPRESSIONS, ONE LINE. They are separate code, so a patch that
 * narrows one and leaves the other reads as finished and is half done. Each
 * shape below names which slot it exercises.
 *
 * THE DEFINITION SURVIVES; ITS METADATA DOES NOT. This is what the failure looks
 * like WHILE the line still ends in a swallow-everything tail: the slot does not
 * match, so the title is not a title and the braces are not the definition's,
 * and what is left over is read as trailing junk rather than as prose.
 * markup-carve/carve#911 anchors the line at end of line and turns both into a
 * paragraph; the two compose, and the shapes here move again when it lands.
 *
 * THE ONE-SPACE FORMS ARE CONTROLS. The narrowing must not close the door on the
 * spelling the language actually uses, which is the whole point of the ruling.
 */
class ReferenceDefinitionMetadataSlotsTakeOneSpaceTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A run at either slot, and what is left of the definition.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runFilledSlotProvider(): array
    {
        return [
            'title slot, two spaces' => ["[a]: /u  \"T\"\n\n[a][]\n", '<p><a href="/u">a</a></p>'],
            'title slot, three spaces' => ["[a]: /u   \"T\"\n\n[a][]\n", '<p><a href="/u">a</a></p>'],
            'title slot, two spaces, single quotes' => ["[a]: /u  'T'\n\n[a][]\n", '<p><a href="/u">a</a></p>'],
            'attributes slot, two spaces' => ["[a]: /u  {.c}\n\n[a][]\n", '<p><a href="/u">a</a></p>'],
            'attributes slot, three spaces' => ["[a]: /u   {.c}\n\n[a][]\n", '<p><a href="/u">a</a></p>'],
        ];
    }

    #[DataProvider('runFilledSlotProvider')]
    public function testARunFillsNeitherSlot(string $source, string $expected): void
    {
        // The WHOLE rendering, so a definition that kept the destination and
        // silently dropped the metadata is distinguishable from one that also
        // lost the destination. Both are wrong; only one of them is this bug.
        $this->assertSame($expected . "\n", $this->html($source));
    }

    /**
     * One space still fills both slots, alone and together.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oneSpaceSlotProvider(): array
    {
        return [
            'title slot' => ["[a]: /u \"T\"\n\n[a][]\n", '<p><a href="/u" title="T">a</a></p>'],
            'attributes slot' => ["[a]: /u {.c}\n\n[a][]\n", '<p><a href="/u" class="c">a</a></p>'],
            'both slots' => ["[a]: /u \"T\" {.c}\n\n[a][]\n", '<p><a href="/u" title="T" class="c">a</a></p>'],
        ];
    }

    #[DataProvider('oneSpaceSlotProvider')]
    public function testOneSpaceStillFillsBothSlots(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", $this->html($source));
    }

    /**
     * NO space at all is a different shape, not a third spelling of one.
     *
     * `[a]: /u{.c}` leaves nothing over: `link_destination` simply reads the
     * braces, so the definition resolves to `href="/u{.c}"` and there are no
     * attributes. It is the trap the ruling names, and it must not move when the
     * slot beside it narrows.
     */
    public function testNoSpaceLeavesTheBracesInTheDestination(): void
    {
        $this->assertSame(
            "<p><a href=\"/u{.c}\">a</a></p>\n",
            $this->html("[a]: /u{.c}\n\n[a][]\n"),
        );
    }

    public function testEverySlotIsStillCovered(): void
    {
        // A row dropped from a provider would take its slot's coverage with it
        // and nothing else here would fail.
        $this->assertCount(5, self::runFilledSlotProvider());
        $this->assertCount(3, self::oneSpaceSlotProvider());
    }
}
