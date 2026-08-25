<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE LAST NEWLINE BEFORE `</code>` IS THE LINE'S TERMINATOR, NOT A LINE
 * (`markup-carve/carve#1708`).
 *
 * A code block's content is bytes the author wrote, so gaining or losing a line
 * is a CONTENT change and not a formatting one.
 *
 * THE RENDERER SETTLES IT RATHER THAN TASTE. This engine writes exactly one
 * newline before the closing tag for a code block whose content is `x`, and two
 * for one whose content ends in a blank line. An importer that strips NONE does
 * not invert its own renderer; one that strips them ALL makes the two documents
 * indistinguishable and loses the line the author wrote. Only removing exactly
 * one is the inverse.
 *
 * This engine used to `rtrim()`, which is the second failure: it took every
 * trailing newline AND every trailing space and tab. Trailing whitespace on the
 * last line of a code block is content for the same reason a blank line is, so
 * the rule is over the NEWLINE alone and a trim is not it.
 *
 * Nothing is reported, in any mode: the byte removed was the terminator, so no
 * content was lost and there is nothing to declare.
 */
class ACodeBlocksLastNewlineIsItsTerminatorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function blockProvider(): array
    {
        return [
            'the terminator does not become a blank line' => [
                "<pre><code>x\n</code></pre>",
                "```\nx\n```\n",
            ],
            'a newline past the terminator is content' => [
                "<pre><code>x\n\n</code></pre>",
                "```\nx\n\n```\n",
            ],
            'two newlines past the terminator are two lines' => [
                "<pre><code>x\n\n\n</code></pre>",
                "```\nx\n\n\n```\n",
            ],
            'a block with no terminator keeps its only line' => [
                '<pre><code>x</code></pre>',
                "```\nx\n```\n",
            ],
            'trailing spaces on the last line are content' => [
                "<pre><code>x  \n</code></pre>",
                "```\nx  \n```\n",
            ],
            'a multi-line block loses no line' => [
                "<pre><code>a\nb\n</code></pre>",
                "```\na\nb\n```\n",
            ],
            'a pre with no code child follows the same rule' => [
                "<pre>x\n</pre>",
                "```\nx\n```\n",
            ],
            'a pre with no code child keeps a real blank line' => [
                "<pre>x\n\n</pre>",
                "```\nx\n\n```\n",
            ],
        ];
    }

    #[DataProvider('blockProvider')]
    public function testTheTerminatorIsStrippedExactlyOnce(string $html, string $expected): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame($expected, $result->value, $mode);
            // Nothing was lost, so nothing is said.
            $this->assertSame([], $result->diagnostics, $mode);
        }
    }

    /**
     * THE PROPERTY THE RULE EXISTS FOR, checked against the renderer rather than
     * against a hand-written expectation.
     *
     * `roundtrip` reads HTML a Carve engine produced, so the import has to be
     * the renderer's inverse for every one of these. Gaining a line on each
     * pass is the failure, and a trim losing the authored blank line is the
     * other one.
     */
    public function testThisEnginesOwnHtmlImportsBackToTheSourceItCameFrom(): void
    {
        $renderer = new CarveConverter();
        $sources = [
            "```\nx\n```\n",
            "```\nx\n\n```\n",
            "```\na\nb\n```\n",
            "```php\necho 1;\n```\n",
        ];
        foreach ($sources as $source) {
            $html = $renderer->convert($source);
            $back = (new HtmlToCarve(importMode: 'roundtrip'))->convert($html);
            $this->assertSame($source, $back, $html);
        }
    }
}
