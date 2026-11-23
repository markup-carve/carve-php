<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6439 row D, Reading 1. C2-B-137: a comment-only line that ends an opaque body closes only the body's container.
 */
final class CommentLineEndsBodyTest extends TestCase
{
    public function testRowDKeepsTheOuterItemOpen(): void
    {
        $this->assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n      </li>\n    </ul>\n"
            . "  </li>\n</ul>\n<p>z</p>",
            Carve::toHtml("- x\n  - ::: |\n    %%%\n%% c\n    %%%\nz\n"),
        );
    }

    public function testRowDPlacesBothCommentsInTheOuterItem(): void
    {
        $list = Carve::parse("- x\n  - ::: |\n    %%%\n%% c\n    %%%\nz\n")->children[0] ?? null;
        $item = $list === null ? null : $list->children[0] ?? null;
        $types = array_map(static fn ($node): string => $node->type, $item === null ? [] : $item->children ?? []);

        $this->assertSame(['paragraph', 'list', 'comment', 'comment'], $types);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bodies(): array
    {
        $fence = "<ul>\n  <li>x\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n"
            . "    <p>y</p>\n  </li>\n</ul>";

        return [
            'code fence, line at the outer content column' => ["- x\n  - ```\n%% c\n  y\n", $fence],
            'code fence, line past the outer content column' => ["- x\n  - ```\n%% c\n    y\n", $fence],
            'comment block' => [
                "- x\n  - %%%\n%% c\n  y\n",
                "<ul>\n  <li>x\n    <ul>\n      <li></li>\n    </ul>\n    <p>y</p>\n  </li>\n</ul>",
            ],
            'quote around the item stays open' => [
                "> - ```\n%% c\n> y\n",
                "<blockquote>\n  <ul>\n    <li>\n      <pre><code></code></pre>\n    </li>\n  </ul>\n  <p>y</p>\n</blockquote>",
            ],
            'outer quote stays open' => [
                "> > ```\n%% c\n> y\n",
                "<blockquote>\n  <blockquote>\n    <pre><code></code></pre>\n  </blockquote>\n  <p>y</p>\n</blockquote>",
            ],
            'region marker still closes every unowned container' => [
                "- x\n  - ```\n%% #region a\n  y\n",
                "<ul>\n  <li>x\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>y</p>",
            ],
        ];
    }

    #[DataProvider('bodies')]
    public function testEveryOpaqueBody(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }

    /**
     * C2-W-123: the description list ends with its description, so the comment is the list's sibling.
     */
    public function testDescriptionListClosesWithTheDescription(): void
    {
        $types = static fn (string $source): array => array_map(
            static fn ($node): string => $node->type,
            Carve::parse($source)->children ?? [],
        );

        $this->assertSame(['definition_list', 'comment'], $types(":: t\n: ```\n%% c\n"));
        $this->assertSame(['definition_list', 'comment', 'paragraph'], $types(":: t\n: ```\n%% c\n: d\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writerSources(): array
    {
        return [
            'nested item' => ["- x\n  - ```\n%% c\n  y\n"],
            'quoted item' => ["> - ```\n%% c\n> y\n"],
        ];
    }

    #[DataProvider('writerSources')]
    public function testWriterOutputReadsTheSameAndIsStable(string $source): void
    {
        $written = Carve::format($source);

        $this->assertSame(Carve::toHtml($source), Carve::toHtml($written));
        $this->assertSame($written, Carve::format($written));
    }
}
