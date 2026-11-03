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
            'c039' => ["> ::: note\n> para\n      ---\ntail\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <p>para</p>\n  </aside>\n</blockquote>\n<hr>\n<p>tail</p>"],
            'c046' => ["> 1. para\n   ---\nx\n", "<blockquote>\n  <ol>\n    <li>para</li>\n  </ol>\n</blockquote>\n<hr>\n<p>x</p>"],
            'c050' => ["1. ::: note\n    # H\ntail\n", "<ol>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<h1 id=\"H\">H</h1>\n</aside>\n</li>\n</ol>\n<p>tail</p>"],
            'c052' => ["1. > para\n         # H\nx\n", "<ol>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ol>\n<p>x</p>"],
            'c057' => ["> ::: note\n> para\n  # H\ntail\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <p>para</p>\n  </aside>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>tail</p>\n</section>"],
            'c061' => ["> - para\n  | a |\nx\n", "<blockquote>\n  <ul>\n    <li>para</li>\n  </ul>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>x</p>"],
            'c076' => ["1. > para\n     # H\ntail\n", "<ol>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ol>\n<p>tail</p>"],
            'c078' => ["> 1. para\n   # H\nx\n", "<blockquote>\n  <ol>\n    <li>para</li>\n  </ol>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>x</p>\n</section>"],
            'c085' => ["> ::: note\n>     # H\ntail\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <h1 id=\"H\">H</h1>\n  </aside>\n</blockquote>\n<p>tail</p>"],
            'c121' => ["1. > para\n     ---\ntail\n", "<ol>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <hr>\n  </li>\n</ol>\n<p>tail</p>"],
            'c123' => ["> 1. para\n   ---\ntail\n", "<blockquote>\n  <ol>\n    <li>para</li>\n  </ol>\n</blockquote>\n<hr>\n<p>tail</p>"],
            'c125' => ["- > para\n        # H\nx\n", "<ul>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n<p>x</p>"],
            'c133' => ["> ::: note\n> para\n   ---\nx\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <p>para</p>\n  </aside>\n</blockquote>\n<hr>\n<p>x</p>"],
            'c137' => ["1. ::: note\n   more\n  ---\ntail\n", "<ol>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>more</p>\n    </aside>\n  </li>\n</ol>\n<hr>\n<p>tail</p>"],
            'c139' => ["> - para\n  | a |\n    tail\n", "<blockquote>\n  <ul>\n    <li>para</li>\n  </ul>\n</blockquote>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>tail</p>"],
            'c149' => ["1. > para\n         | a |\nx\n", "<ol>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n  </li>\n</ol>\n<p>x</p>"],
            'c167' => ["> ::: note\n> para\n  ---\n  tail\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <p>para</p>\n  </aside>\n</blockquote>\n<hr>\n<p>tail</p>"],
            'c168' => ["> ::: note\n> para\n   # H\nx\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <p>para</p>\n  </aside>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>x</p>\n</section>"],
            'c171' => ["- > para\n   | a |\nx\n", "<ul>\n  <li>\n    <blockquote><p>para</p></blockquote>\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n  </li>\n</ul>\n<p>x</p>"],
            'c178' => ["> ::: note\n>  | a |\ntail\n", "<blockquote>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n  </aside>\n</blockquote>\n<p>tail</p>"],
            'c079' => ["> [^c]: para\n>       - li\nx\n", "<blockquote>\n\n</blockquote>"],
            'c153' => ["> [^c]: para\n>   - li\ntail\n", "<blockquote>\n\n</blockquote>"],
            'c193' => ["- > - para\n  >   [^f]: n\n   >     more\n", "<ul>\n<li>\n<blockquote>\n<ul>\n<li>para</li>\n</ul>\n</blockquote>\n</li>\n</ul>"],
            'c194' => ["- > > - para\n    >   [^f]: n\ntail[^f]\n", "<ul>\n  <li>\n    <blockquote>\n      <blockquote>\n        <ul>\n          <li>para</li>\n        </ul>\n      </blockquote>\n    </blockquote>\n  </li>\n</ul>"],
            'marked list thematic' => ["> 1. para\n>    ---\nx\n", '<blockquote><ol><li>para <hr></li></ol></blockquote><p>x</p>'],
            'marked item heading' => ["1. > para\n   > # H\ntail\n", '<ol><li><blockquote><p>para</p><h1 id="H">H</h1></blockquote></li></ol><p>tail</p>'],
            'closed paragraph' => ["> para\n\n  # H\nx\n", "<blockquote><p>para</p></blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>x</p>\n</section>"],
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
