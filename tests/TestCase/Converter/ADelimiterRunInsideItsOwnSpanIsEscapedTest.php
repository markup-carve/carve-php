<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A run of the delimiter a formatting element is written with closes that
 * element at its first character, so the importer escapes the run, as the
 * Carve writer does for the same tree (#2139).
 */
class ADelimiterRunInsideItsOwnSpanIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a URL in an emphasis' => ['em', 'emphasis', 'see http://a.b/c-d.e'],
            'a slash run in an emphasis' => ['em', 'emphasis', 'a // b'],
            'an asterisk run in a strong' => ['strong', 'strong', 'a ** b'],
            'a tilde run in a strike' => ['s', 'strike', 'a ~~ b'],
            'an underscore run in an underline' => ['u', 'underline', 'a __ b'],
            'an equals run in a highlight' => ['mark', 'highlight', 'a == b'],
            'a longer run' => ['em', 'emphasis', 'a /// b'],
            'a single delimiter is left alone' => ['em', 'emphasis', 'a / b'],
            'a slash inside a word' => ['em', 'emphasis', 'a/b'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheImportMatchesTheWriter(string $tag, string $type, string $text): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => $type, 'children' => [['type' => 'text', 'value' => $text]]],
                    ],
                ],
            ],
        ]);

        $imported = (new HtmlToCarve())->convert("<p><$tag>" . htmlspecialchars($text, ENT_NOQUOTES) . "</$tag></p>");

        $this->assertSame((new CarveRenderer())->render($document), $imported);
        $this->assertSame(
            (new CarveConverter())->render($document),
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * BOUND: a run outside such a span, and one in a code span, are untouched.
     */
    public function testARunTheSpanDoesNotEncloseIsUntouched(): void
    {
        $this->assertSame("a // b\n", (new HtmlToCarve())->convert('<p>a // b</p>'));
        $this->assertSame("/a `x//y` b/\n", (new HtmlToCarve())->convert('<p><em>a <code>x//y</code> b</em></p>'));
    }
}
