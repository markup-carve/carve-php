<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare leading Markdown rule is frontmatter only when its body has the #2799 mapping shape.
 */
final class LeadingThematicBreakTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function officialExamples(): iterable
    {
        $withHeadings = "---\nFoo\n---\nBar\n---\nBaz\n";
        $headingsSource = "***\n\n== Foo\n\n== Bar\n\nBaz\n";
        $headingsHtml = "<hr>\n<section id=\"Foo\">\n  <h2>Foo</h2>\n</section>\n"
            . "<section id=\"Bar\">\n  <h2>Bar</h2>\n  <p>Baz</p>\n</section>";
        $twoRules = "---\n---\n";
        $rulesSource = "***\n\n***\n";
        $rulesHtml = "<hr>\n<hr>";

        yield 'CommonMark 0.31 example 96' => ['markdown', $withHeadings, $headingsSource, $headingsHtml];
        yield 'CommonMark 0.31 example 98' => ['markdown', $twoRules, $rulesSource, $rulesHtml];
        yield 'GFM 0.29 example 66' => ['gfm', $withHeadings, $headingsSource, $headingsHtml];
        yield 'GFM 0.29 example 68' => ['gfm', $twoRules, $rulesSource, $rulesHtml];
    }

    #[DataProvider('officialExamples')]
    public function testOfficialLeadingRuleExamples(string $format, string $input, string $source, string $html): void
    {
        $result = Carve::importDocument($input, ['from' => $format]);
        self::assertSame($source, $result->source);
        self::assertSame($html, Carve::render($result->source));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function mappingShapes(): iterable
    {
        yield 'scalar' => ['Foo', false];
        yield 'empty' => ['', false];
        yield 'comments only' => ["# note\n# more", false];
        yield 'list first' => ["- one\n- two", false];
        yield 'hyphen-prefixed key' => ['-foo: bar', false];
        yield 'indented key' => ['  title: Hi', false];
        yield 'colon in key' => ['a:b: Hi', false];
        yield 'mapping' => ['title: Hi', true];
        yield 'empty value' => ['title:', true];
        yield 'tab after colon' => ["title:\tHi", true];
        yield 'comment before key' => ["# note\ntitle: Hi", true];
        yield 'double quoted key' => ['"my title": Hi', true];
        yield 'single quoted key' => ["'my title': Hi", true];
        yield 'malformed value' => ['title: [unclosed', true];
    }

    #[DataProvider('mappingShapes')]
    public function testBareFenceNeedsMappingShape(string $body, bool $mapping): void
    {
        foreach (['markdown', 'gfm'] as $format) {
            $source = Carve::importDocument("---\n" . $body . "\n---\nTail\n", ['from' => $format])->source;
            self::assertSame($mapping ? '---' : '***', substr($source, 0, 3), $format . ': ' . $body);
        }
    }

    public function testOneThousandNearbyInputsKeepTheirFirstBlock(): void
    {
        $bodies = [
            ['Foo', false], ['', false], ['# note', false], ['- one', false], ['  title: Hi', false],
            ['a:b: Hi', false], ['title: Hi', true], ['title:', true], ["# note\ntitle: Hi", true],
            ['"my title": Hi', true], ["'my title': Hi", true], ['title: [unclosed', true],
        ];
        $suffixes = ["Bar\n---\nBaz", "Bar\n===", "\n---\n\nBar", "\n***\nBar", "\n___\nBar"];
        $openers = ['---', '***', '___'];
        for ($i = 0; $i < 500; $i++) {
            [$body, $mapping] = $bodies[$i % count($bodies)];
            $break = $i % 2 === 0 ? "\n" : "\r\n";
            $suffix = $suffixes[intdiv($i, count($bodies)) % count($suffixes)];
            $opener = $openers[intdiv($i, count($bodies) * count($suffixes)) % count($openers)];
            $input = $opener . $break . str_replace("\n", $break, $body) . $break . '---' . $break
                . str_replace("\n", $break, $suffix) . $break;
            foreach (['markdown', 'gfm'] as $format) {
                $source = Carve::importDocument($input, ['from' => $format])->source;
                self::assertSame($opener === '---' && $mapping ? '---' : '***', substr($source, 0, 3), $format . ' variant ' . $i);
            }
        }
    }
}
