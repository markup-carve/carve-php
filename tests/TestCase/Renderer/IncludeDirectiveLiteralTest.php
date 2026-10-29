<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IncludeDirectiveLiteralTest extends TestCase
{
    #[DataProvider('directiveProvider')]
    public function testDirectiveBodyStaysLiteralInCoreHtml(string $source, string $directive): void
    {
        $html = CarveConverter::create()->convert($source);

        $this->assertStringContainsString($directive, $html);
        $this->assertStringNotContainsString('class="mention"', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directiveProvider(): iterable
    {
        yield 'invalid leading zero' => [
            '{{ data/p2.csv @code:csv @lines:01-2 }}',
            '{{ data/p2.csv @code:csv @lines:01-2 }}',
        ];

        yield 'invalid reverse range' => [
            '{{ data/p2.csv @code:txt @lines:3-1 }}',
            '{{ data/p2.csv @code:txt @lines:3-1 }}',
        ];

        yield 'unresolved path' => [
            '{{ missing.crv @code:php @lines:0-2 }}',
            '{{ missing.crv @code:php @lines:0-2 }}',
        ];
    }

    public function testUnresolvedQuotedPathWithInvalidRangeStaysLiteral(): void
    {
        $html = CarveConverter::create()->convert('{{ "missing file.crv" @code:php @lines:01-2 }}');

        $this->assertSame(
            '<p>{{ "missing file.crv" @code:php @lines:01-2 }}</p>',
            $html,
        );
    }

    public function testEmbeddedInvalidRangeDirectiveStaysLiteral(): void
    {
        $html = CarveConverter::create()->convert('Before {{ data/p2.csv @code:csv @lines:01-2 }} after.');

        $this->assertSame('<p>Before {{ data/p2.csv @code:csv @lines:01-2 }} after.</p>', $html);
    }

    public function testOtherInvalidOptionsKeepTheCodeIncludeLineLiteral(): void
    {
        $html = CarveConverter::create()->convert('{{ a.js @code:js @code:py }}');

        $this->assertSame('<p>{{ a.js @code:js @code:py }}</p>', $html);
    }

    public function testInvalidCodeIncludeUsesOrdinaryContainerParagraphs(): void
    {
        $directive = '{{ data/p2.csv @code:csv @lines:0-2 }}';
        $converter = CarveConverter::create();

        $this->assertSame(
            "<blockquote><p>{$directive}</p></blockquote>",
            $converter->convert('> ' . $directive),
        );
        $this->assertSame(
            "<ul>\n  <li>{$directive}</li>\n</ul>",
            $converter->convert('- ' . $directive),
        );
    }

    public function testLiteralMultilineReferenceUsesSoftBreakInPlainAndAnsi(): void
    {
        foreach (['[t][r' => '[t][r x]', '![a][r' => '![a][r x]'] as $start => $expected) {
            $source = $start . "\nx]\n\n[r x]: /u\n";
            foreach ([CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
                $this->assertStringContainsString($expected, $converter->convert($source));
            }
        }
    }
}
