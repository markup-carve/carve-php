<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A definition is written WHERE IT WAS AUTHORED, on every non-HTML target.
 *
 * The renderers placed the whole set at one end of the body, chosen by
 * `hasAbbreviationsBeforeBody()`. Two positions is one fewer than a document can
 * express, so a definition authored BETWEEN two blocks moved to an end and
 * `parse(fmt(x)) != parse(x)` (PART 11 section 1). The parser already keeps the
 * node in place because PART 12 section 7 refuses to collect it, so the two
 * halves of this engine disagreed about the same clause. carve-js and carve-rs
 * both keep it.
 */
class AbbreviationDefinitionKeepsItsPositionTest extends TestCase
{
    protected string $midDocument = "a\n\n*[AB]: x\n\nb AB\n";

    /**
     * @return array<string, array{string, string}>
     */
    public static function midDocumentTargets(): array
    {
        return [
            'carve' => ['carve', "a\n\n*[AB]: x\n\nb AB\n"],
            // The line is gone on this target, because `b AB` consumes it and
            // PART 11 §10f drops a consumed definition here. The position it
            // would have kept is proved below on a definition nothing consumes,
            // which is the only shape of it this target still writes.
            'plain' => ['plainText', "a\n\nb AB (x)\n"],
            'markdown' => ['markdown', "a\n\n*[AB]: x\n\nb <abbr title=\"x\">AB</abbr>\n"],
        ];
    }

    /**
     * The plain target's half of the position rule, on the definition it still
     * writes: nothing references `AB`, so PART 11 §10a keeps the line and §10f
     * leaves it where the author put it - between the two blocks, not at an end.
     */
    public function testAnUnconsumedDefinitionStaysBetweenTheTwoBlocksOnPlain(): void
    {
        $this->assertSame(
            "a\n\n*[AB]: x\n\nb\n",
            CarveConverter::plainText()->convert("a\n\n*[AB]: x\n\nb\n"),
        );
    }

    #[DataProvider('midDocumentTargets')]
    public function testTheDefinitionStaysBetweenTheTwoBlocks(string $target, string $expected): void
    {
        $out = $target === 'carve'
            ? CarveConverter::create()->toCarve($this->midDocument)
            : CarveConverter::$target()->convert($this->midDocument);

        $this->assertSame($expected, $out);
    }

    public function testTheCarveTargetSatisfiesTheParseInvariant(): void
    {
        $converter = CarveConverter::create();
        $types = static fn (Document $d): array => array_map(
            static fn ($c): string => (new ReflectionClass($c))->getShortName(),
            $d->getChildren(),
        );

        $before = $types($converter->parse($this->midDocument));
        $after = $types($converter->parse($converter->toCarve($this->midDocument)));

        $this->assertSame($before, $after);
        $this->assertSame(['Paragraph', 'AbbreviationDefinition', 'Paragraph'], $before);
    }

    /**
     * A definition with no source line of its own - the API path that the AST
     * codec and the ProseMirror bridge use - still has to be written. It has no
     * position to keep, so it goes at the end the flag names.
     *
     * This is the case the first attempt at the fix broke, so it is a proof and
     * not a bound: rendering only from nodes drops it entirely.
     */
    #[DataProvider('everyNonHtmlRenderer')]
    public function testADefinitionSetThroughTheApiIsStillWritten(string $renderer): void
    {
        $document = CarveConverter::create()->parse("text\n");
        $document->setAbbreviations(['HTML' => 'HyperText']);

        $this->assertStringContainsString('*[HTML]: HyperText', (new $renderer())->render($document));
    }

    /**
     * The other end. A definition with no node is placed by the flag, so both
     * settings of it have to be exercised or half the placement is unmeasured.
     */
    #[DataProvider('everyNonHtmlRenderer')]
    public function testAnApiDefinitionFollowsTheBeforeBodyFlag(string $renderer): void
    {
        $document = CarveConverter::create()->parse("text\n");
        $document->setAbbreviations(['HTML' => 'HyperText']);
        $document->setAbbreviationsBeforeBody(true);

        $out = (new $renderer())->render($document);

        $this->assertStringContainsString('*[HTML]: HyperText', $out);
        $this->assertLessThan(
            (int)mb_strpos($out, 'text'),
            (int)mb_strpos($out, '*[HTML]'),
            'the definition should precede the body when the flag says so',
        );
    }

    /**
     * @return array<string, array{class-string<\MarkupCarve\Carve\Renderer\CarveRenderer|\MarkupCarve\Carve\Renderer\MarkdownRenderer|\MarkupCarve\Carve\Renderer\PlainTextRenderer|\MarkupCarve\Carve\Renderer\AnsiRenderer>}>
     */
    public static function everyNonHtmlRenderer(): array
    {
        return [
            'carve' => [CarveRenderer::class],
            'markdown' => [MarkdownRenderer::class],
            'plain' => [PlainTextRenderer::class],
            'ansi' => [AnsiRenderer::class],
        ];
    }

    /**
     * A definition already at an end has nothing to move, so RELOCATION is not
     * what these two catch - they would pass a writer that still placed every
     * definition by the flag. They catch DUPLICATION: reading the map as well as
     * the node writes the line twice, and both of these fail against that
     * mutation. Kept for exactly that, since the map is still read for the
     * definitions that have no node.
     */
    public function testADefinitionAtTheTopStaysAtTheTop(): void
    {
        $this->assertSame("*[AB]: x\n\na AB\n", CarveConverter::create()->toCarve("*[AB]: x\n\na AB\n"));
    }

    public function testADefinitionAtTheBottomStaysAtTheBottom(): void
    {
        $this->assertSame("a AB\n\n*[AB]: x\n", CarveConverter::create()->toCarve("a AB\n\n*[AB]: x\n"));
    }
}
