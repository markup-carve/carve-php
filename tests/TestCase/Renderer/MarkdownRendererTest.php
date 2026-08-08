<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    protected CarveConverter $converter;

    protected MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new MarkdownRenderer();
    }

    public function testBasicParagraph(): void
    {
        $djot = 'Hello world!';
        $document = $this->converter->parse($djot);

        $this->assertSame("Hello world!\n", $this->renderer->render($document));
    }

    /**
     * A line block's line structure is carried by hard breaks, and the PARSER
     * already puts one in the AST per newline inside the block. Rewriting every
     * newline in the renderer added a SECOND hard break on top of each of those,
     * and turned the blank line between two stanzas into a pair of them
     * (carve#352).
     */
    public function testLineBlockUsesTheHardBreaksAlreadyInTheAst(): void
    {
        $document = $this->converter->parse("::: |\nStanza one,\nstill one.\n\nStanza two.\n:::\n");

        $this->assertSame(
            "Stanza one,\\\nstill one.\n\nStanza two.\n",
            $this->renderer->render($document),
        );
    }

    public function testEmphasis(): void
    {
        $djot = 'This is /emphasized/ text.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('*emphasized*', $this->renderer->render($document));
    }

    public function testStrong(): void
    {
        $djot = 'This is *strong* text.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('**strong**', $this->renderer->render($document));
    }

    public function testHeadings(): void
    {
        $djot = "# Heading 1\n\n## Heading 2\n\n### Heading 3";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('# Heading 1', $result);
        $this->assertStringContainsString('## Heading 2', $result);
        $this->assertStringContainsString('### Heading 3', $result);
    }

    public function testLinks(): void
    {
        $djot = '[Example](https://example.com)';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('[Example](https://example.com)', $this->renderer->render($document));
    }

    public function testDivRendersQuotedOpenerHeaderNotTitleAttribute(): void
    {
        $document = $this->converter->parse("{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::");

        $this->assertSame("**opener title**\n\nBody.\n", $this->renderer->render($document));
    }

    public function testDivHeaderRendersInlineContent(): void
    {
        $document = $this->converter->parse("::: note \"a *b* `c`\"\nx\n:::");

        $this->assertSame("**a b `c`**\n\nx\n", $this->renderer->render($document));
    }

    public function testDivHeaderPreservesEmphasisInsideBoldTitle(): void
    {
        $document = $this->converter->parse("::: note \"a /em/ d\"\nx\n:::");

        $this->assertSame("**a *em* d**\n\nx\n", $this->renderer->render($document));
    }

    public function testDivHeaderUnwrapsStrongRecursivelyButBodyDoesNot(): void
    {
        $document = $this->converter->parse("::: note \"a */b/*\"\nbody *strong*\n:::");

        $this->assertSame("**a *b***\n\nbody **strong**\n", $this->renderer->render($document));
    }

    public function testLinkDestinationsEncodeMarkdownBreakoutCharacters(): void
    {
        // A `)` reaching a destination via a reference definition (URL runs to
        // end-of-line, not `)`-delimited) is percent-encoded so it cannot break
        // out of the `(...)` in Markdown output.
        $document = $this->converter->parse("[x][r]\n\n[r]: https://e.com/a)b");

        $this->assertSame("[x](https://e.com/a%29b)\n", $this->renderer->render($document));
    }

    public function testDangerousAutolinkDestinationIsSanitized(): void
    {
        $document = $this->converter->parse('<javascript:alert(1)>');

        $this->assertSame("[javascript:alert(1)]()\n", $this->renderer->render($document));
    }

    public function testAutolinkDestinationIsUnchangedWhenSafe(): void
    {
        $document = $this->converter->parse('<https://example.com>');

        $this->assertSame("[https://example.com](https://example.com)\n", $this->renderer->render($document));
    }

    public function testImageDestinationsEncodeMarkdownBreakoutCharacters(): void
    {
        $document = $this->converter->parse('![x](https://e.com/a <b>)');

        // The `[` goes bare under PART 11 section 8a M1b - it is not adjacent to
        // another `[` - while `]` keeps M1 (M1c). The label still cannot close, so
        // nothing breaks out; what this case is about, the destination's `<b>`
        // being neutralized, is unchanged.
        $this->assertSame("![x\\](https://e.com/a &lt;b&gt;)\n", $this->renderer->render($document));
    }

    public function testLinkWithTitle(): void
    {
        $djot = '[Example](https://example.com "Title")';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('[Example](https://example.com "Title")', $this->renderer->render($document));
    }

    public function testImages(): void
    {
        $djot = '![Alt text](image.png)';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('![Alt text](image.png)', $this->renderer->render($document));
    }

    public function testCodeBlock(): void
    {
        $djot = "```php\necho \"Hello\";\n```";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('```php', $result);
        $this->assertStringContainsString('echo "Hello";', $result);
        $this->assertStringContainsString('```', $result);
    }

    public function testCodeBlockFenceHeaderIsPreserved(): void
    {
        $document = $this->converter->parse("```php \"config/app.php\"\necho 1;\n```");
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('```php "config/app.php"', $result);
    }

    public function testCodeBlockLabelIsPreserved(): void
    {
        $document = $this->converter->parse("```php [Installation]\necho 1;\n```");
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('```php [Installation]', $result);
    }

    public function testCodeBlockHeaderAndLabelInSpecOrder(): void
    {
        $document = $this->converter->parse("```php \"app.php\" [Main]\necho 1;\n```");
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('```php "app.php" [Main]', $result);
    }

    public function testCodeBlockHeaderAndLabelRoundTrip(): void
    {
        $source = "```php \"app.php\" [Main]\necho 1;\n```";
        $once = $this->renderer->render($this->converter->parse($source));
        $twice = $this->renderer->render($this->converter->parse($once));

        // Re-parsing the rendered Markdown reproduces the fence header and label.
        $this->assertSame($once, $twice);
        $this->assertStringContainsString('"app.php"', $twice);
        $this->assertStringContainsString('[Main]', $twice);
    }

    public function testCodeBlockHeaderBacktickIsStrippedSoOutputRoundTrips(): void
    {
        // A longer fence lets the title carry a backtick; the emitted opener must
        // not reintroduce a clashing backtick run.
        $source = "````php \"a`b\"\necho 1;\n````";
        $once = $this->renderer->render($this->converter->parse($source));
        $twice = $this->renderer->render($this->converter->parse($once));

        $this->assertStringContainsString('```php "ab"', $once);
        $this->assertSame($once, $twice);
    }

    public function testInlineCode(): void
    {
        $djot = 'Use `print()` function.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('`print()`', $this->renderer->render($document));
    }

    public function testUnorderedList(): void
    {
        // Test dash marker
        $djot = "- Item 1\n- Item 2\n- Item 3";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
        $this->assertStringContainsString('- Item 3', $result);

        // Test asterisk marker (round-trip)
        $djot = "* Item 1\n* Item 2";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('* Item 1', $result);
        $this->assertStringContainsString('* Item 2', $result);

        // Test plus marker (round-trip)
        $djot = "+ Item 1\n+ Item 2";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('+ Item 1', $result);
        $this->assertStringContainsString('+ Item 2', $result);
    }

    public function testOrderedList(): void
    {
        // Numeric with dot - preserved
        $djot = "1. First\n2. Second\n3. Third";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);
        $this->assertStringContainsString('3. Third', $result);

        // Numeric with parenthesis - preserved (valid CommonMark)
        $djot = "1) First\n2) Second";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('1) First', $result);
        $this->assertStringContainsString('2) Second', $result);

        // Alphabetic - normalized to numeric (not standard Markdown)
        $djot = "a. First\nb. Second\nc. Third";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);

        // Roman numerals - normalized to numeric (not standard Markdown)
        $djot = "i. First\nii. Second\niii. Third";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);

        // Parenthesized (1) is NOT a Carve list marker - it stays literal.
        $djot = "(1) First\n(2) Second";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('(1) First', $result);
        $this->assertStringContainsString('(2) Second', $result);
    }

    public function testTaskList(): void
    {
        $djot = "- [ ] Todo\n- [x] Done";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('[ ]', $result);
        $this->assertStringContainsString('[x]', $result);
    }

    public function testBlockQuote(): void
    {
        $djot = '> This is quoted text.';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('> This is quoted text.', $result);
    }

    /**
     * Every marker renders as `---`, whichever one the author wrote.
     *
     * The marker is not part of the canonical AST -- carve-js, whose shape PART 12
     * pins, has no field for it -- and this engine's own canonical writer
     * normalizes it too, so reproducing it here made the Markdown target disagree
     * with the Carve target of the same document (carve#352).
     */
    public function testThematicBreakIsNormalizedToDashes(): void
    {
        foreach (['***', '---', '___'] as $marker) {
            $document = $this->converter->parse("Above\n\n{$marker}\n\nBelow");
            $result = $this->renderer->render($document);

            $this->assertStringContainsString('---', $result, "authored as {$marker}");
            $this->assertStringNotContainsString('***', $result, "authored as {$marker}");
            $this->assertStringNotContainsString('___', $result, "authored as {$marker}");
        }
    }

    public function testThematicBreakAgreesWithTheCarveTarget(): void
    {
        $source = "Above\n\n***\n\nBelow\n";
        $markdown = $this->renderer->render($this->converter->parse($source));

        $this->assertStringContainsString('---', $markdown);
        $this->assertStringContainsString('---', CarveConverter::toCarve($source));
    }

    public function testTable(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('| A | B |', $result);
        $this->assertStringContainsString('| --- | --- |', $result);
        $this->assertStringContainsString('| 1 | 2 |', $result);
    }

    public function testTableAlignment(): void
    {
        $djot = "| Left | Center | Right |\n|:-----|:------:|------:|\n| L | C | R |";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString(':---', $result);
        $this->assertStringContainsString(':---:', $result);
        $this->assertStringContainsString('---:', $result);
    }

    public function testSuperscript(): void
    {
        $djot = 'E=mc{^2^}';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<sup>2</sup>', $result);
    }

    public function testSubscript(): void
    {
        $djot = 'H{,2,}O';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<sub>2</sub>', $result);
    }

    public function testHighlight(): void
    {
        $djot = 'Text =highlighted= here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<mark>highlighted</mark>', $result);
    }

    public function testDelete(): void
    {
        $djot = 'Text {-deleted-} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertSame("Text <del>deleted</del> here\n", $result);
    }

    public function testSubstitutionUsesDelAndInsTags(): void
    {
        $djot = 'Text {~a~>b~} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertSame("Text <del>a</del><ins>b</ins> here\n", $result);
    }

    public function testSubstitutionEmitsControlCharacters(): void
    {
        // PART 9 §29 T2: the non-whitespace C0 controls are CONTENT and this
        // target emits them. carve-rs cdac42c publishes the same bytes.
        $djot = "Text {~a\x1bx~>b\x1by~} here";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertSame("Text <del>a\x1bx</del><ins>b\x1by</ins> here\n", $result);
    }

    public function testInsert(): void
    {
        $djot = 'Text {+inserted+} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<ins>inserted</ins>', $result);
    }

    public function testFootnote(): void
    {
        $djot = "Text[^1]\n\n[^1]: Footnote content";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('[^1]', $result);
        $this->assertStringContainsString('[^1]: Footnote content', $result);
    }

    public function testMathInline(): void
    {
        $djot = 'Equation $`E = mc^2` here.';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('$E = mc^2$', $result);
    }

    public function testMathDisplay(): void
    {
        $djot = '$$`x^2 + y^2 = z^2`';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('$$x^2 + y^2 = z^2$$', $result);
    }

    public function testHardBreak(): void
    {
        $djot = "Line 1\\\nLine 2";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // A hard break is a BACKSLASH before the newline, never two trailing
        // spaces (PART 11 section 9): both mean `<br />` to a CommonMark reader,
        // but whitespace is stripped by editors and CI, and losing one of the two
        // spaces makes the break vanish rather than degrade.
        $this->assertStringContainsString("\\\n", $result);
        $this->assertStringNotContainsString("  \n", $result);
    }

    public function testRawHtml(): void
    {
        // Raw HTML is ESCAPED, not emitted, in Markdown output: it would become
        // live again when the Markdown is rendered to HTML downstream.
        $djot = 'Text `<span>raw</span>`{=html} more';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('&lt;span&gt;raw&lt;/span&gt;', $result);
        $this->assertStringNotContainsString('<span>raw</span>', $result);
    }

    public function testDefinitionList(): void
    {
        $djot = ":: Term\n:  Definition";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // Definition lists approximated with bold + colon prefix
        $this->assertStringContainsString('**Term**', $result);
        $this->assertStringContainsString(': Definition', $result);
    }

    public function testDefinitionListMultipleTermsMultipleDefinitions(): void
    {
        $djot = ":: color\n:: colour\n:  The visual property.\n:  Used in design.";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // Multiple terms
        $this->assertStringContainsString('**color**', $result);
        $this->assertStringContainsString('**colour**', $result);
        // Multiple definitions
        $this->assertSame(2, substr_count($result, ': '));
        $this->assertStringContainsString('The visual property.', $result);
        $this->assertStringContainsString('Used in design.', $result);
    }

    public function testComplexDocument(): void
    {
        $this->markTestSkipped('Pending later phase: depends on Carve table syntax / Markdown converter output not in Phase 1.');

        $djot = <<<'DJOT'
# Welcome

This is a *paragraph* with _emphasis_.

## Features

- Item one
- Item two

```php
echo "Hello";
```
DJOT;

        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('# Welcome', $result);
        $this->assertStringContainsString('**paragraph**', $result);
        $this->assertStringContainsString('*emphasis*', $result);
        $this->assertStringContainsString('## Features', $result);
        $this->assertStringContainsString('- Item one', $result);
        $this->assertStringContainsString('```php', $result);
    }

    public function testEventReplaceContent(): void
    {
        $this->renderer->on('render.symbol', function (RenderEvent $event): void {
            $symbol = $event->getNode();
            if ($symbol instanceof Symbol) {
                $emoji = match ($symbol->getName()) {
                    'heart' => ':heart_emoji:',
                    'star' => ':star_emoji:',
                    default => ':' . $symbol->getName() . ':',
                };
                $event->setHtml($emoji);
            }
        });

        $djot = 'I :heart: Djot!';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString(':heart_emoji:', $result);
    }

    public function testEventWildcard(): void
    {
        $nodeTypes = [];
        $this->renderer->on('render.*', function (RenderEvent $event) use (&$nodeTypes): void {
            $nodeTypes[] = $event->getNode()->getType();
        });

        $djot = "# Hello\n\nWorld";
        $document = $this->converter->parse($djot);
        $this->renderer->render($document);

        $this->assertContains('heading', $nodeTypes);
        $this->assertContains('paragraph', $nodeTypes);
        $this->assertContains('text', $nodeTypes);
    }

    public function testEventOff(): void
    {
        $called = false;
        $this->renderer->on('render.paragraph', function () use (&$called): void {
            $called = true;
        });

        $this->renderer->off('render.paragraph');
        $djot = 'Test paragraph';
        $document = $this->converter->parse($djot);
        $this->renderer->render($document);

        $this->assertFalse($called);
    }

    public function testEventPreventDefault(): void
    {
        $this->renderer->on('render.heading', function (RenderEvent $event): void {
            $event->setHtml('CUSTOM_HEADING_TEXT');
        });

        $djot = '# Original Title';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('CUSTOM_HEADING_TEXT', $result);
        $this->assertStringNotContainsString('Original Title', $result);
    }

    // ==================== Round-Trip Tests ====================
    // Tests: Djot → MarkdownRenderer → MarkdownToCarve → Djot

    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(string $djot, string $expected, string $description): void
    {
        $this->markTestSkipped('Pending Phase 8: Markdown<->Carve converter still emits Djot syntax.');

        $markdownToDjot = new MarkdownToCarve();

        // Djot → AST → Markdown
        $document = $this->converter->parse($djot);
        $markdown = $this->renderer->render($document);

        // Markdown → Djot
        $djotBack = $markdownToDjot->convert($markdown);

        $this->assertSame($expected, trim($djotBack), $description);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'paragraph' => [
                'Hello world!',
                'Hello world!',
                'Simple paragraph should round-trip',
            ],
            'heading' => [
                '# Heading 1',
                '# Heading 1',
                'Heading should round-trip',
            ],
            'emphasis' => [
                'Text with _emphasis_ here.',
                'Text with _emphasis_ here.',
                'Emphasis should round-trip',
            ],
            'strong' => [
                'Text with *strong* here.',
                'Text with *strong* here.',
                'Strong should round-trip',
            ],
            'link' => [
                '[Example](https://example.com)',
                '[Example](https://example.com)',
                'Link should round-trip',
            ],
            'code_block' => [
                "```php\necho 'hello';\n```",
                "```php\necho 'hello';\n```",
                'Code block should round-trip',
            ],
            'unordered_list' => [
                "- Item 1\n- Item 2",
                "- Item 1\n- Item 2",
                'Unordered list should round-trip',
            ],
            'ordered_list' => [
                "1. First\n2. Second",
                "1. First\n2. Second",
                'Ordered list should round-trip',
            ],
            'blockquote' => [
                '> Quoted text here.',
                '> Quoted text here.',
                'Blockquote should round-trip',
            ],
            'thematic_break_dash' => [
                "Above\n\n---\n\nBelow",
                "Above\n\n---\n\nBelow",
                'Thematic break with dashes should round-trip',
            ],
            'thematic_break_star' => [
                "Above\n\n***\n\nBelow",
                "Above\n\n---\n\nBelow",
                'Thematic break markers normalize to dashes',
            ],
        ];
    }

    /**
     * Tests elements that go through HTML but round-trip back to Djot
     */
    public function testRoundTripViaHtml(): void
    {
        $this->markTestSkipped('Pending Phase 8: Markdown<->Carve converter still emits Djot syntax.');

        $markdownToDjot = new MarkdownToCarve();

        $cases = [
            ['^superscript^', '^superscript^', 'Superscript via <sup>'],
            ['~subscript~', '~subscript~', 'Subscript via <sub>'],
            ['{=highlight=}', '{=highlight=}', 'Highlight via <mark>'],
            ['{+insert+}', '{+insert+}', 'Insert via <ins>'],
            [':smile:', ':smile:', 'Symbol preserved'],
            ['$`x^2`', '$`x^2`', 'Inline math'],
            ['$$`x^2`', '$$`x^2`', 'Display math'],
        ];

        foreach ($cases as [$djot, $expected, $description]) {
            $document = $this->converter->parse($djot);
            $markdown = $this->renderer->render($document);
            $djotBack = trim($markdownToDjot->convert($markdown));

            $this->assertSame($expected, $djotBack, $description);
        }
    }

    /**
     * Documents features that are truly lost in round-trip
     */
    public function testRoundTripLossy(): void
    {
        $markdownToDjot = new MarkdownToCarve();

        // Span attributes are lost - no way to represent in Markdown
        $djot = '[span text]{.class #id}';
        $document = $this->converter->parse($djot);
        $markdown = $this->renderer->render($document);
        $djotBack = trim($markdownToDjot->convert($markdown));

        $this->assertSame('span text', $djotBack, 'Span attributes are lost');
    }
}
