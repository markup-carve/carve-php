<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code fence with no closer, ended by a CONTAINER's closer or by a bare quote
 * marker, keeps the blank line at the end of its content.
 *
 * The fence collector refused to absorb a blank LAST line, to avoid the phantom
 * element `explode("\n", ...)` leaves behind for a terminal newline. That
 * refusal also rejected the genuine blank at the end of a container body, so a
 * fence closed by `:::` or by the end of a quote came out a line short
 * (carve-php#1177). The phantom is now dropped in splitLines(), where the
 * string is known to be a whole document.
 *
 * The oracle and carve-js keep the blank in every one of these shapes.
 */
class ContainerClosedFenceKeepsItsBlankTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function code(string $source): string
    {
        $html = $this->converter->convert($source);

        return preg_match('#<code[^>]*>(.*?)</code>#s', $html, $m) === 1 ? $m[1] : '(no code block)';
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function containerShapes(): array
    {
        return [
            'a div closer ends the fence' => ["::: note\n```\nx\n\n:::\n", "x\n\n"],
            'two blanks before a div closer' => ["::: note\n```\nx\n\n\n:::\n", "x\n\n\n"],
            'a bare quote marker ends the fence' => ["> ```\n> x\n>\n", "x\n\n"],
        ];
    }

    #[DataProvider('containerShapes')]
    public function testAContainerClosedFenceKeepsItsTrailingBlank(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->code($source));
    }

    /**
     * These two were already correct and are the reason the phantom-element
     * guard existed at all. They are BOUNDS: moving the drop to splitLines()
     * must leave them exactly as they were.
     */
    public function testTheShapesThatAlreadyWorkedAreUnchanged(): void
    {
        $this->assertSame("x\n\n", $this->code("- ```\n  x\n\n"));
        $this->assertSame("x\n\n", $this->code("```\nx\n\n"));
    }

    /**
     * BOUND: the phantom element itself. A document ending in a single newline
     * has no trailing blank line, and a document with no trailing newline at
     * all must parse the same way.
     */
    public function testATerminalNewlineIsNotContent(): void
    {
        $this->assertSame("x\n", $this->code("```\nx\n"));
        $this->assertSame("x\n", $this->code("```\nx"));
        $this->assertSame("x\n", $this->code("```\nx\n```\n"));
    }
}
