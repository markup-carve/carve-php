<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 enumerates the task states exhaustively:
 *
 *   task_state = ' ' | 'x' | 'X' | '-' | '_' | '>' | '?' ;
 *
 * `x`/`X` render a CHECKED checkbox, the other five an UNCHECKED one.
 *
 * This engine accepted ANY single character, which did not merely reinterpret
 * the brackets - it DELETED them. `- [!] urgent` rendered as a checkbox plus
 * ` urgent`, so a document using a bracketed one-character tag at the head of a
 * list item (a priority flag, a status glyph) silently lost it and gained a
 * checkbox nobody wrote (carve-php#657).
 *
 * carve-js is the engine that matched the grammar here; carve-rs has the same
 * over-permissive reading and needs the same fix.
 */
final class TaskStateEnumerationTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function enumeratedStateProvider(): array
    {
        return [
            'space is unchecked' => [' ', false],
            'lowercase x is checked' => ['x', true],
            'uppercase X is checked' => ['X', true],
            'dash is unchecked' => ['-', false],
            'underscore is unchecked' => ['_', false],
            'greater-than is unchecked' => ['>', false],
            'question mark is unchecked' => ['?', false],
        ];
    }

    #[DataProvider('enumeratedStateProvider')]
    public function testAnEnumeratedStateIsATaskMarker(string $state, bool $checked): void
    {
        $html = $this->converter->convert("- [{$state}] item\n");

        $this->assertStringContainsString('<input type="checkbox"', $html);
        if ($checked) {
            $this->assertStringContainsString('checked', $html);
        } else {
            $this->assertStringNotContainsString('checked', $html);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonStateProvider(): array
    {
        return [
            'a letter outside the set' => ['d'],
            'a digit' => ['1'],
            'a hash' => ['#'],
            'an asterisk' => ['*'],
            'a bang' => ['!'],
            'a tilde' => ['~'],
        ];
    }

    #[DataProvider('nonStateProvider')]
    public function testACharacterOutsideTheEnumerationIsLiteralText(string $char): void
    {
        $html = $this->converter->convert("- [{$char}] item\n");

        $this->assertStringNotContainsString('<input', $html);
        // The point of the bug: the bracket text must still be THERE.
        $this->assertStringContainsString("[{$char}] item", $html);
    }

    public function testTheBracketTextIsNotDeleted(): void
    {
        // The exact document from the issue.
        $this->assertSame(
            "<ul>\n  <li>[!] urgent</li>\n  <li>[~] maybe</li>\n</ul>\n",
            $this->converter->convert("- [!] urgent\n- [~] maybe\n"),
        );
    }

    public function testTwoCharactersAreStillRejected(): void
    {
        // Already correct before the fix; here so a change that reached for
        // "widen the class" instead of "enumerate it" does not pass unnoticed.
        $html = $this->converter->convert("- [ab] item\n");

        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringContainsString('[ab] item', $html);
    }
}
