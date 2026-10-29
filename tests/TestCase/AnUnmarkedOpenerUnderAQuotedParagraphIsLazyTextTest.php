<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnUnmarkedOpenerUnderAQuotedParagraphIsLazyTextTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'c039' => ["> ::: note\n> para\n      ---\ntail\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n—\ntail</p>\n</aside>\n</blockquote>"],
            'c046' => ["> 1. para\n   ---\nx\n", "<blockquote>\n<ol>\n<li>para\n—\nx</li>\n</ol>\n</blockquote>"],
            'c050' => ["1. ::: note\n    # H\ntail\n", "<ol>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<h1 id=\"H\">H</h1>\n</aside>\n</li>\n</ol>\n<p>tail</p>"],
            'c052' => ["1. > para\n         # H\nx\n", "<ol>\n<li>\n<blockquote><p>para\n# H\nx</p></blockquote>\n</li>\n</ol>"],
            'c057' => ["> ::: note\n> para\n  # H\ntail\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n# H\ntail</p>\n</aside>\n</blockquote>"],
            'c061' => ["> - para\n  | a |\nx\n", "<blockquote>\n<ul>\n<li>para\n| a |\nx</li>\n</ul>\n</blockquote>"],
            'c076' => ["1. > para\n     # H\ntail\n", "<ol>\n<li>\n<blockquote><p>para\n# H\ntail</p></blockquote>\n</li>\n</ol>"],
            'c078' => ["> 1. para\n   # H\nx\n", "<blockquote>\n<ol>\n<li>para\n# H\nx</li>\n</ol>\n</blockquote>"],
            'c085' => ["> ::: note\n>     # H\ntail\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p># H\ntail</p>\n</aside>\n</blockquote>"],
            'c121' => ["1. > para\n     ---\ntail\n", "<ol>\n<li>\n<blockquote><p>para\n—\ntail</p></blockquote>\n</li>\n</ol>"],
            'c123' => ["> 1. para\n   ---\ntail\n", "<blockquote>\n<ol>\n<li>para\n—\ntail</li>\n</ol>\n</blockquote>"],
            'c125' => ["- > para\n        # H\nx\n", "<ul>\n<li>\n<blockquote><p>para\n# H\nx</p></blockquote>\n</li>\n</ul>"],
            'c133' => ["> ::: note\n> para\n   ---\nx\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n—\nx</p>\n</aside>\n</blockquote>"],
            'c137' => ["1. ::: note\n   more\n  ---\ntail\n", "<ol>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more\n—\ntail</p>\n</aside>\n</li>\n</ol>"],
            'c139' => ["> - para\n  | a |\n    tail\n", "<blockquote>\n<ul>\n<li>para\n| a |\ntail</li>\n</ul>\n</blockquote>"],
            'c149' => ["1. > para\n         | a |\nx\n", "<ol>\n<li>\n<blockquote><p>para\n| a |\nx</p></blockquote>\n</li>\n</ol>"],
            'c167' => ["> ::: note\n> para\n  ---\n  tail\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n—\ntail</p>\n</aside>\n</blockquote>"],
            'c168' => ["> ::: note\n> para\n   # H\nx\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n# H\nx</p>\n</aside>\n</blockquote>"],
            'c171' => ["- > para\n   | a |\nx\n", "<ul>\n<li>\n<blockquote><p>para\n| a |\nx</p></blockquote>\n</li>\n</ul>"],
            'c178' => ["> ::: note\n>  | a |\ntail\n", "<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>| a |\ntail</p>\n</aside>\n</blockquote>"],
            'c079' => ["> [^c]: para\n>       - li\nx\n", "<blockquote>\n\n</blockquote>"],
            'c153' => ["> [^c]: para\n>   - li\ntail\n", "<blockquote>\n\n</blockquote>"],
            'c193' => ["- > - para\n  >   [^f]: n\n   >     more\n", "<ul>\n<li>\n<blockquote>\n<ul>\n<li>para</li>\n</ul>\n</blockquote>\n</li>\n</ul>"],
            'c194' => ["- > > - para\n    >   [^f]: n\ntail[^f]\n", "<ul>\n<li>\n<blockquote>\n<blockquote>\n<ul>\n<li>para\n&gt;   [^f]: n\ntail[^f]</li>\n</ul>\n</blockquote>\n</blockquote>\n</li>\n</ul>"],
            'marked list thematic' => ["> 1. para\n>    ---\nx\n", '<blockquote><ol><li>para <hr></li></ol></blockquote><p>x</p>'],
            'marked item heading' => ["1. > para\n   > # H\ntail\n", '<ol><li><blockquote><p>para</p><h1 id="H">H</h1></blockquote></li></ol><p>tail</p>'],
            'closed paragraph' => ["> para\n\n  # H\nx\n", '<blockquote><p>para</p></blockquote><p># H x</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testQuoteOwnership(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $normalize = static function (string $html): string {
            $collapsed = preg_replace('/\s+/u', ' ', $html);
            if ($collapsed === null) {
                throw new RuntimeException('Invalid HTML encoding');
            }
            $blocks = preg_replace('/>\s+</', '><', trim($collapsed));
            if ($blocks === null) {
                throw new RuntimeException('Could not normalize HTML');
            }

            return $blocks;
        };
        self::assertSame($normalize($expected), $normalize($converter->convert($source)));
    }
}
