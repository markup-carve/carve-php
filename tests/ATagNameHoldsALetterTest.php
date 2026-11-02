<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mention or tag name holds an ASCII letter (carve#4809).
 */
class ATagNameHoldsALetterTest extends TestCase
{
    public function testDigitOnlyNamesStayText(): void
    {
        self::assertSame(
            '<p>See issue #123, #1 priority and ' . self::tag('fff') . '.</p>',
            self::html('See issue #123, #1 priority and #fff.'),
        );
    }

    /**
     * @return array<array{string, string}>
     */
    public static function names(): array
    {
        return [
            ['#1.2', '#1.2'], ['#1-2', '#1-2'], ['#-1', '#-1'], ['#_1', '#_1'], ['#1.', '#1.'], ['#1#a', '#1#a'],
            ['#123{.k}', '#123{.k}'], ['#v2', self::tag('v2')], ['#1a', self::tag('1a')], ['#1.a.', self::tag('1.a') . '.'],
            ['#-a', self::tag('-a')], ['@5', '@5'], ['@_', '@_'], ['@1@a', '@1@a'],
            ['@1.a', '<span class="mention"><strong>@1.a</strong></span>'],
        ];
    }

    #[DataProvider('names')]
    public function testName(string $source, string $html): void
    {
        self::assertSame("<p>$html</p>", self::html($source));
    }

    public function testADigitHashIsNoCaptionPlaceholder(): void
    {
        self::assertSame(
            "<table>\n  <caption>Figure #1 q</caption>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>Paid @5 today and see #123</p>",
            self::html("| a |\n^ Figure #1 q\n\nPaid @5 today and see #123"),
        );
        self::assertStringContainsString('<figcaption>Fig #1 1</figcaption>', self::html("![p](p.png)\n^ Fig #1 #"));
        self::assertStringContainsString('<figcaption>Fig #_</figcaption>', self::html("![p](p.png)\n^ Fig #_"));
    }

    public function testANameCutToDigitsByAClosingUnderlineIsText(): void
    {
        self::assertSame('<p><u>x #1</u> y</p>', self::html('_x #1_ y'));
        self::assertSame('<p><u>x @1</u>-a y</p>', self::html('_x @1_-a y'));
    }

    public function testTheWriterKeepsADigitOnlyNameText(): void
    {
        $source = "#123 and @5\n";
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert(CarveConverter::toCarve($source)));
    }

    public function testTheWriterRefusesALetterlessTag(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 5,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'tag', 'name' => '123']]]],
        ]);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    private static function tag(string $name): string
    {
        return '<span class="tag"><strong>#' . $name . '</strong></span>';
    }

    private static function html(string $source): string
    {
        return trim((new CarveConverter())->convert("$source\n"));
    }
}
