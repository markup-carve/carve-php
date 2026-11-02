<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4717 and markup-carve/carve#4798: no later closer pulls a host's sibling into an attached block.
 */
final class AnAttachedColonBlockEndsAtItsHostSiblingTest extends TestCase
{
    /**
     * @var string
     */
    private const EMPTY_DIV = "<div>\n\n</div>";

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'list item' => ["- +\n:::\n- b\n:::\n", "<ul>\n  <li>\n    <div>\n\n    </div>\n  </li>\n  <li>b</li>\n</ul>\n" . self::EMPTY_DIV],
            'ordered item' => ["1. +\n:::\n2. b\n:::\n", "<ol>\n  <li>\n    <div>\n\n    </div>\n  </li>\n  <li>b</li>\n</ol>\n" . self::EMPTY_DIV],
            'body before the sibling' => ["- +\n:::\nx\n- b\n:::\n", "<ul>\n  <li>\n    <div>\n      <p>x</p>\n    </div>\n  </li>\n  <li>b</li>\n</ul>\n" . self::EMPTY_DIV],
            'a closer before the sibling still closes' => ["- +\n:::\nx\n:::\n- b\n", "<ul>\n  <li>\n    <div>\n      <p>x</p>\n    </div>\n  </li>\n  <li>b</li>\n</ul>"],
            'a marker in a fence is no sibling' => ["- +\n:::\n~~~\n- y\n~~~\n:::\n", "<ul>\n  <li>\n    <div>\n      <pre><code>- y\n</code></pre>\n    </div>\n  </li>\n</ul>"],
            'description body' => [":: t\n: +\n:::\n: b\n:::\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n\n    </div>\n  </dd>\n  <dd>b</dd>\n</dl>\n" . self::EMPTY_DIV],
            'description term' => [":: t\n: +\n:::\n:: u\n: v\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n\n    </div>\n  </dd>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>"],
            'own-line plus in a description' => [":: t\n: a\n+\n:::\n: b\n:::\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <div>\n\n    </div>\n  </dd>\n  <dd>b</dd>\n</dl>\n" . self::EMPTY_DIV],
        ];
    }

    #[DataProvider('shapes')]
    public function testASiblingEndsTheBlock(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }

    /**
     * markup-carve/carve#4661: an indented colon run in a nested line block stays payload and leaves the stanza open.
     */
    public function testANestedLineBlockKeepsItsLazyStanza(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>a<br>\nlazy<br>\n&nbsp;:::<br>\ntail</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            (new CarveConverter())->convert("- - ::: |\n    a\nlazy\n     :::\ntail\n"),
        );
    }
}
