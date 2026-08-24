<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `type` on an `<input>` is an ENUMERATED attribute, and HTML matches an
 * enumerated keyword ASCII case-insensitively. `<input type="CHECKBOX">` is a
 * checkbox to every browser, so an importer that compares the value exactly
 * reads a real task list as an ordinary bullet and the task state leaves the
 * document with nothing said.
 *
 * All three engines compared exactly, so nothing diverged and no cross-engine
 * gate could see it - which is why this is pinned per SPELLING rather than on
 * the one uppercase shape that prompted it. A fix tested only on `CHECKBOX`
 * still misses `Checkbox`.
 *
 * This engine says the rule in THREE places - the direct-checkbox lookup, the
 * skip inside the list-item content walk, and processListItemContent() - and
 * recognizing without removing is its own defect: the item would then carry
 * both a `[ ]` marker and the raw element. The removal is asserted here for
 * that reason.
 */
class AnInputSTypeMatchesTheCheckboxKeywordCaseInsensitivelyTest extends TestCase
{
    private HtmlToCarve $importer;

    protected function setUp(): void
    {
        $this->importer = new HtmlToCarve();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return [
            'lower' => ['checkbox'],
            'upper' => ['CHECKBOX'],
            'initial cap' => ['Checkbox'],
            'mixed' => ['chEckBox'],
            'inverted' => ['cHECKBOx'],
        ];
    }

    #[DataProvider('spellings')]
    public function testATaskItemIsReadWhateverCaseItsTypeIsWrittenIn(string $spelling): void
    {
        $this->assertSame(
            "- [ ] a\n",
            $this->importer->convert('<ul><li><input type="' . $spelling . '"> a</li></ul>'),
        );
        $this->assertSame(
            "- [x] a\n",
            $this->importer->convert('<ul><li><input type="' . $spelling . '" checked> a</li></ul>'),
        );
    }

    /**
     * Recognizing the checkbox has to also REMOVE it - the half a lookup-only
     * fix would miss.
     */
    #[DataProvider('spellings')]
    public function testTheCheckboxIsConsumedByTheMarkerRatherThanLeftInTheContent(string $spelling): void
    {
        $imported = $this->importer->convert('<ul><li><input type="' . $spelling . '"> a</li></ul>');
        $this->assertStringNotContainsString('input', $imported);
        $this->assertStringNotContainsString('=html', $imported);
    }

    /**
     * The control. A fix that matched loosely - a prefix test, a substring
     * test - would turn every text input at the head of an item into a task
     * marker.
     */
    public function testANonCheckboxInputIsStillNotATaskItem(): void
    {
        foreach (['text', 'TEXT', 'checkboxes', 'radio'] as $type) {
            $this->assertStringNotContainsString(
                '[ ]',
                $this->importer->convert('<ul><li><input type="' . $type . '"> a</li></ul>'),
                sprintf('type="%s" must not read as a task marker', $type),
            );
        }
    }

    /**
     * The fold is ASCII. `strtolower()` has been locale-independent and
     * ASCII-only since PHP 8.2, which is what HTML's rule says; a
     * Unicode-aware fold would additionally read `CHEC` + U+212A KELVIN SIGN
     * + `BOX` as the keyword, which no browser does.
     */
    public function testTheFoldIsAsciiSoAKelvinSignIsNotAK(): void
    {
        $this->assertStringNotContainsString(
            '[ ]',
            $this->importer->convert("<ul><li><input type=\"CHEC\u{212A}BOX\"> a</li></ul>"),
        );
    }
}
