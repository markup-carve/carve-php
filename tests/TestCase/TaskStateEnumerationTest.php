<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 enumerates the task states exhaustively:
 *
 *   task_marker = '[', task_state, ']', space ;
 *   task_state = ' ' | 'x' | 'X' | '-' | '_' | '>' | '?' ;
 *
 * This engine accepted ANY single character, which deleted the bracket text:
 * `- [!] urgent` rendered a checkbox and dropped the `!`. carve-js implements
 * the enumeration; carve-rs shares this defect (carve-rs#471, carve-php#657).
 */
class TaskStateEnumerationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function enumeratedStates(): array
    {
        return [
            'space' => [' '],
            'lowercase x' => ['x'],
            'uppercase X' => ['X'],
            'dash' => ['-'],
            'underscore' => ['_'],
            'greater than' => ['>'],
            'question mark' => ['?'],
        ];
    }

    #[DataProvider('enumeratedStates')]
    public function testAnEnumeratedStateIsATaskItem(string $state): void
    {
        $html = $this->converter->convert("- [$state] item\n");

        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function testOnlyXIsChecked(): void
    {
        $this->assertStringContainsString('checked', $this->converter->convert("- [x] item\n"));
        $this->assertStringContainsString('checked', $this->converter->convert("- [X] item\n"));
        $this->assertStringNotContainsString('checked', $this->converter->convert("- [?] item\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statesOutsideTheEnumeration(): array
    {
        return [
            'letter' => ['d'],
            'digit' => ['1'],
            'hash' => ['#'],
            'asterisk' => ['*'],
            'bang' => ['!'],
            'tilde' => ['~'],
        ];
    }

    #[DataProvider('statesOutsideTheEnumeration')]
    public function testACharacterOutsideTheEnumerationStaysLiteral(string $state): void
    {
        $html = $this->squash($this->converter->convert("- [$state] item\n"));

        $this->assertSame("<ul> <li>[$state] item</li> </ul>", $html);
    }

    public function testTheBracketTextSurvives(): void
    {
        // The sharp end: the marker was not reinterpreted, it was deleted.
        $html = $this->converter->convert("- [!] urgent\n");

        $this->assertStringContainsString('[!]', $html);
        $this->assertStringNotContainsString('checkbox', $html);
    }

    public function testTwoCharactersWereAlreadyRejected(): void
    {
        $this->assertSame(
            '<ul> <li>[ab] item</li> </ul>',
            $this->squash($this->converter->convert("- [ab] item\n")),
        );
    }
}
