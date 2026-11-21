<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Degraded forms and source lines that differed from carve-js next2 in the render differential.
 */
final class DegradedLayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function cases(): array
    {
        return [
            'include paragraph carries its source line (C2-H-016)' => [
                "Top.\n\n{{ a.crv }}",
                ['sourceLines' => true],
                "<p data-source-line=\"1\">Top.</p>\n<p data-source-line=\"3\">{{ a.crv }}</p>",
            ],
            'degraded table alone in a quote (C2-H-165)' => [
                '> | a |',
                ['profile' => 'comment'],
                '<blockquote><p>a</p></blockquote>',
            ],
            'degraded block in a loose item (C2-H-165)' => [
                "- ```js\n  x\n  ```\n\n- * inner",
                ['profile' => 'minimal'],
                "<ul>\n  <li><p>x</p></li>\n  <li>\n    <ul>\n      <li>inner</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'degraded raw block alone in a description (C2-H-165)' => [
                ":: t\n: ```=html\n  <b>\n  ```",
                ['profile' => 'article'],
                "<dl>\n  <dt>t</dt>\n  <dd>&lt;b&gt;</dd>\n</dl>",
            ],
            'item whose block leaves no text (C2-H-165)' => [
                "- ```s\t\"\"\n***",
                ['profile' => 'minimal'],
                "<ul>\n  <li></li>\n</ul>\n<p>---</p>",
            ],
            'item whose later block leaves no text (C2-H-165)' => [
                "- x\n\n  ```\n  ```",
                ['profile' => 'minimal'],
                "<ul>\n  <li>x</li>\n</ul>",
            ],
            'degraded lead that leaves no text (C2-H-165)' => [
                "- ```\n  ```\n\n  after\n- b",
                ['profile' => 'minimal'],
                "<ul>\n  <li>after</li>\n  <li>b</li>\n</ul>",
            ],
            'caption inside a quote degraded as a whole (C2-H-165)' => [
                "> \$\$`x^2`\n> ^ Equation",
                ['profile' => 'minimal'],
                '<p>&gt; x^2 Equation</p>',
            ],
            'caption of an empty quote degraded as a whole (C2-H-165)' => [
                "> >\n> ^ c\n^ c",
                ['profile' => 'minimal'],
                '<p>&gt; c ^ c</p>',
            ],
            'captioned image in a degraded div (C2-H-165)' => [
                "::: figure\n![b](b.png)\n^ (b) B\n\n:::: figure\n![a](a.png)\n^ (a) A\n::::\n:::",
                ['profile' => 'minimal'],
                "<p>[img: b]</p>\n<p>(b) B</p>\n<p>[img: a] (a) A</p>",
            ],
            'table inside a quote degraded as a whole (C2-H-165)' => [
                "> |={colspan=2} H |\n> | a | b |",
                ['profile' => 'minimal'],
                '<p>&gt; H a | b</p>',
            ],
            'list item that leaves no text (C2-H-165)' => [
                "::: figure\n- ::: figure\n  :::\n:::\n",
                ['profile' => 'minimal'],
                '',
            ],
        ];
    }

    /**
     * @param string $input
     * @param array<string, mixed> $options
     * @param string $html
     */
    #[DataProvider('cases')]
    public function testRender(string $input, array $options, string $html): void
    {
        self::assertSame($html, Carve::render($input, $options));
    }

    /**
     * C2-H-165: a degraded node is reported once, also where it is the only block of its container.
     */
    public function testDegradedBlockInAQuoteIsReportedOnce(): void
    {
        foreach (["> :: a\n> : b", '> | a |', ":: t\n: | a |\n\n  | b |"] as $input) {
            $codes = array_map(
                static fn ($d): string => $d->code,
                Carve::renderWithDiagnostics($input, ['profile' => 'comment'])->diagnostics,
            );
            self::assertSame(['profile-construct-degraded'], array_values(array_unique($codes)), $input);
            self::assertCount(1, $codes, $input);
        }
    }

    /**
     * C2-N-077: a degraded include is reported once, as degraded, and not as unresolved.
     */
    public function testDegradedIncludeIsNotReportedUnresolved(): void
    {
        foreach (['{{ a.crv }}', "- {{ a.crv }}\n- b", '> {{ a.crv }}'] as $input) {
            $codes = Carve::renderWithDiagnostics($input, ['profile' => 'comment'])->codes();
            self::assertContains('profile-construct-degraded', $codes);
            self::assertNotContains('include-unresolved', $codes);
        }
        self::assertContains('include-unresolved', Carve::renderWithDiagnostics('{{ a.crv }}')->codes());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function emptiedParagraphs(): array
    {
        return [
            'quote' => ["> [](javascript:x)\n>\n> after", 'comment', '<blockquote><p>after</p></blockquote>'],
            'nested quote' => [
                "> > [](javascript:x)\n> >\n> > after",
                'comment',
                "<blockquote>\n  <blockquote><p>after</p></blockquote>\n</blockquote>",
            ],
            'item' => ["- [](javascript:x)\n\n  after", 'comment', "<ul>\n  <li>after</li>\n</ul>"],
            'ordered item' => ["1. [](javascript:x)\n\n   after", 'comment', "<ol>\n  <li>after</li>\n</ol>"],
            'link then a comment line' => ["- [](javascript:x)\n  {% c %}\n\n  after", 'comment', "<ul>\n  <li>after</li>\n</ul>"],
            'item in a quote' => [
                "> - [](javascript:x)\n>\n>   after",
                'comment',
                "<blockquote>\n  <ul>\n    <li>after</li>\n  </ul>\n</blockquote>",
            ],
            'refused link under minimal' => ["- [](javascript:x)\n\n  after", 'minimal', "<ul>\n  <li>after</li>\n</ul>"],
        ];
    }

    /**
     * C2-H-019, C2-H-006, C2-H-007, C2-H-034: a paragraph a refused link leaves writing nothing is no visible child.
     */
    #[DataProvider('emptiedParagraphs')]
    public function testEmptiedParagraphIsNotVisible(string $input, string $profile, string $html): void
    {
        self::assertSame($html, Carve::render($input, ['profile' => $profile]));
    }

    /**
     * C2-H-069: a hard break keeps the paragraph; the denied destination is reported once.
     */
    public function testEmptiedParagraphKeepsHardBreakAndReportsOnce(): void
    {
        self::assertSame("<ul>\n  <li><br>\nb</li>\n</ul>", Carve::render("- [](javascript:x)\\\n  b", ['profile' => 'comment']));
        self::assertSame(['destination-denied'], Carve::renderWithDiagnostics('> [](javascript:x)', ['profile' => 'comment'])->codes());
    }
}
