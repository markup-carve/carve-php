<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnItemLineUnderAPlainParagraphStaysLazyInTheCloserScanTest extends TestCase
{
    #[DataProvider('provider')]
    public function testCloserScanKeepsTheParagraphState(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        $lazyPrefix = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>para\n";
        $lazySuffix = "\n<code>\ncode</code></p>\n  <div>\n    <div>\n      <p>after <strong>b</strong></p>\n    </div>\n  </div>\n</aside>";
        $itemPrefix = "<aside class=\"admonition note\" aria-label=\"Note\">\n";
        $itemSuffix = "  <pre><code>code\n::::\n</code></pre>\n</aside>\n<p>after <strong>b</strong></p>";

        return [
            'plain paragraph, bullet comment' => ["::: note\npara\n- %% c\n```\ncode\n::::\n:::\nafter *b*\n", $lazyPrefix . '-' . $lazySuffix],
            'plain paragraph, bullet attribute' => ["::: note\npara\n- {#x}\n```\ncode\n::::\n:::\nafter *b*\n", $lazyPrefix . '- {<span class="tag"><strong>#x</strong></span>}' . $lazySuffix],
            'plain paragraph, ordered comment' => ["::: note\npara\n2. %% c\n```\ncode\n::::\n:::\nafter *b*\n", $lazyPrefix . '2.' . $lazySuffix],
            'item paragraph, bullet comment' => ["::: note\n- para\n- %% c\n```\ncode\n::::\n:::\nafter *b*\n", $itemPrefix . "  <ul>\n    <li>para</li>\n    <li></li>\n  </ul>\n" . $itemSuffix],
            'item paragraph, bullet attribute' => ["::: note\n- para\n- {#x}\n```\ncode\n::::\n:::\nafter *b*\n", $itemPrefix . "  <ul>\n    <li>para</li>\n    <li></li>\n  </ul>\n" . $itemSuffix],
            'item paragraph, ordered comment' => ["::: note\n1. para\n2. %% c\n```\ncode\n::::\n:::\nafter *b*\n", $itemPrefix . "  <ol>\n    <li>para</li>\n    <li></li>\n  </ol>\n" . $itemSuffix],
            'item paragraph, nested comment' => ["::: note\n- para\n  - %% c\n```\ncode\n::::\n:::\nafter *b*\n", $itemPrefix . "  <ul>\n    <li>para\n      <ul>\n        <li></li>\n      </ul>\n    </li>\n  </ul>\n" . $itemSuffix],
        ];
    }
}
