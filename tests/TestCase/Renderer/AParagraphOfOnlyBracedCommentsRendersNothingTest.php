<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph of only braced comments renders nothing, as a `%%` line does (carve#4799, superseding ruling C).
 */
class AParagraphOfOnlyBracedCommentsRendersNothingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'between two paragraphs' => ["a\n\n{% c %}\n\nb\n", "<p>a</p>\n<p>b</p>"],
            'two comments on one line' => ["a\n\n{% c %} {% d %}\n\nb\n", "<p>a</p>\n<p>b</p>"],
            'with a trailing line comment' => ["{% c %} %% t\n\nz\n", '<p>z</p>'],
            'in a quote' => ["> a\n>\n> {% c %}\n", '<blockquote><p>a</p></blockquote>'],
            'text after the comment stays' => ["{% c %} x\n", '<p> x</p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testItRendersNothing(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAListenerThatRendersTheCommentKeepsTheParagraph(): void
    {
        $converter = CarveConverter::create();
        $converter->on('render.comment', static function (RenderEvent $event): void {
            $event->setHtml('<b>x</b>');
            $event->preventDefault();
        });

        $this->assertSame('<p><b>x</b></p>', $converter->convert("{% c %}\n"));
    }

    public function testAListenerOnTheLineBreakKeepsTheParagraph(): void
    {
        $converter = CarveConverter::create();
        $converter->on('render.soft_break', static function (RenderEvent $event): void {
            $event->setHtml('<br>');
            $event->preventDefault();
        });

        $this->assertSame('<p><br></p>', $converter->convert("{% c %}\n{% d %}\n"));
    }
}
