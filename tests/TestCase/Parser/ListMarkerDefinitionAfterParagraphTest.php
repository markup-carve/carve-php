<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListMarkerDefinitionAfterParagraphTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lazyDefinitions(): iterable
    {
        yield 'link at document level' => ["para\n- [d]: u\n\n[go][d]\n", "para\n- [d]: u"];
        yield 'link in quote' => ["> r\n> - [d]: u\n\n[go][d]\n", "r\n- [d]: u"];
        yield 'link in div' => ["::: n\nr\n- [d]: u\n:::\n\n[go][d]\n", "r\n- [d]: u"];
        yield 'footnote at document level' => ["para\n- [^f]: t\n\nsee[^f]\n", "para\n- [^f]: t"];
        yield 'footnote in quote' => ["> r\n> - [^f]: t\n\nsee[^f]\n", "r\n- [^f]: t"];
    }

    #[DataProvider('lazyDefinitions')]
    public function testDefinitionOnLazyMarkerLineIsOnlyParagraphText(string $source, string $text): void
    {
        $html = $this->converter->convert($source);

        self::assertStringContainsString($text, $html);
        self::assertStringNotContainsString('href="u"', $html);
        self::assertStringNotContainsString('doc-noteref', $html);
        self::assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testHeadingLeavesNoParagraphForFootnoteMarkerToContinue(): void
    {
        $html = $this->converter->convert("# h\n- [^f]: t\n\n[^f] ref\n");

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('role="doc-noteref"', $html);
        self::assertStringContainsString('role="doc-endnotes"', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function columnZeroControls(): iterable
    {
        yield 'indented item prose' => ["- a\n  more\n* [d]: u\n\n[go][d]\n"];
        yield 'lazy item prose' => ["- a\nlazy\n* [d]: u\n\n[go][d]\n"];
        yield 'quote under top-level prose' => ["para\n> - [d]: u\n\n[go][d]\n"];
    }

    #[DataProvider('columnZeroControls')]
    public function testColumnZeroMarkerStillCollects(string $source): void
    {
        self::assertStringContainsString('<a href="u">go</a>', $this->converter->convert($source));
    }

    public function testAbbreviationMarkerStillStaysParagraphText(): void
    {
        $html = $this->converter->convert("para\n* [A]: alpha\n\nA\n");

        self::assertStringContainsString("para\n* [A]: alpha", $html);
        self::assertStringNotContainsString('<abbr', $html);
    }

    public function testLegacyCustomPatternIsRegisteredOnDefinitionProbe(): void
    {
        $this->converter->getParser()->addBlockPattern(
            '/^CUSTOM$/',
            static function (array $lines, int $start, $parent, $parser): ?int {
                $end = $start + 1;
                $lineCount = count($lines);
                while ($end < $lineCount && $lines[$end] !== '') {
                    $end++;
                }

                $paragraph = new Paragraph();
                $paragraph->appendChild(new Text(implode("\n", array_slice($lines, $start, $end - $start))));
                $parent->appendChild($paragraph);

                return $end - $start;
            },
        );

        $html = $this->converter->convert("CUSTOM\nbody\n- [d]: u\n\n[go][d]\n");

        // The real parse gives the whole non-blank run to CUSTOM. Its scratch
        // probe must do the same, so the marker remains content, not metadata.
        self::assertStringContainsString("CUSTOM\nbody\n- [d]: u", $html);
        self::assertStringNotContainsString('href="u"', $html);
    }
}
