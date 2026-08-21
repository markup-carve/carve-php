<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The CSS-only tab mechanism is a radio input plus a label. The radio is the
 * control: it is what a keyboard reaches and what a screen reader announces.
 * `display: none` on it removes the control from the focus order and from the
 * accessibility tree at once, leaving labels that are neither focusable nor
 * operable - so a page styled with the recipe this repo publishes has tabs
 * nobody can switch without a mouse, and exposes exactly one panel.
 *
 * The docblock recipe is the only styling guidance the org publishes, so it is
 * what gets copied. This pins that it stays the visually-hidden-but-focusable
 * pattern, and that the focus ring is drawn somewhere visible - the input
 * itself is invisible, so the ring has to land on its label.
 */
class ThePublishedRadioCssKeepsTheControlFocusableTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function extensionProvider(): array
    {
        return [
            'tabs' => [TabsExtension::class, 'tabs-radio', 'tabs-label'],
            'code group' => [CodeGroupExtension::class, 'code-group-radio', 'code-group-label'],
        ];
    }

    /**
     * @param class-string $extension
     * @param string $labelClass
     * @param string $radioClass
     */
    #[DataProvider('extensionProvider')]
    public function testTheRadioIsNeverDisplayNone(string $extension, string $radioClass, string $labelClass): void
    {
        $css = $this->publishedCss($extension);

        $this->assertStringNotContainsString(
            '.' . $radioClass . ' { display: none; }',
            $css,
            'the radio carries the focus, so it must not be removed from the layer tree',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.' . preg_quote($radioClass, '/') . '\s*\{[^}]*\b(display:\s*none|visibility:\s*hidden)/',
            $css,
            'the radio carries the focus, so it must not be removed from the layer tree',
        );
    }

    /**
     * @param class-string $extension
     * @param string $labelClass
     * @param string $radioClass
     */
    #[DataProvider('extensionProvider')]
    public function testTheRadioIsHiddenVisuallyAndStaysFocusable(string $extension, string $radioClass, string $labelClass): void
    {
        $css = $this->publishedCss($extension);

        $this->assertMatchesRegularExpression(
            '/\.' . preg_quote($radioClass, '/') . '\s*\{[^}]*clip-path:\s*inset\(50%\)/',
            $css,
            'the radio is hidden by clipping it, which keeps it focusable',
        );
    }

    /**
     * @param class-string $extension
     * @param string $labelClass
     * @param string $radioClass
     */
    #[DataProvider('extensionProvider')]
    public function testFocusIsVisibleOnTheLabelTheRadioControls(string $extension, string $radioClass, string $labelClass): void
    {
        $css = $this->publishedCss($extension);

        $this->assertMatchesRegularExpression(
            '/\.' . preg_quote($radioClass, '/') . ':focus-visible\s*\+\s*\.' . preg_quote($labelClass, '/') . '\s*\{[^}]*outline:/',
            $css,
            'the input is invisible, so its focus ring belongs on the label',
        );
    }

    /**
     * The `Required CSS` fenced block out of the extension's own docblock.
     *
     * @param class-string $extension
     */
    protected function publishedCss(string $extension): string
    {
        $doc = (new ReflectionClass($extension))->getDocComment();
        $this->assertIsString($doc, $extension . ' must keep a docblock');

        $plain = (string)preg_replace('/^\s*\*\s?/m', '', $doc);

        $this->assertSame(
            1,
            preg_match('/Required CSS:\s*```css\n(.*?)\n```/s', $plain, $matches),
            $extension . ' must publish a `Required CSS` recipe',
        );

        return $matches[1];
    }
}
