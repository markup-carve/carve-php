<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (rule A, carve#3885; PART 9 §17 L3/L4).
 */
final class AFirstBlockFollowerAnswersToItsOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'description item leaves a document heading (#4172)' => [":: t\n: - +\n# H\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>+</li>\n    </ul>\n  </dd>\n</dl>\n<section id=\"H\">\n  <h1>H</h1>\n</section>"];
        yield 'description item leaves a list at the body column (#4197)' => [":: t\n: 1. +\n    - z\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>+\n- z</li>\n    </ol>\n  </dd>\n</dl>"];
        yield 'ordered item leaves a list below its content column' => ["1. +\n  - z\n", "<ol>\n  <li>+\n- z</li>\n</ol>"];
        yield 'wide ordered item leaves a list below its content column' => ["10. +\n   - z\n", "<ol start=\"10\">\n  <li>+\n- z</li>\n</ol>"];
        yield 'quoted first block keeps its lazy line' => ["> - +\n> s\ntail\n", "<blockquote>\n  <ul>\n    <li>s\ntail</li>\n  </ul>\n</blockquote>"];
        yield 'refused trailing plus folds into the attached paragraph (#4196)' => ["- q\n+\nx\n+\n", "<ul>\n  <li>q\n    x\n+\n  </li>\n</ul>"];
        yield 'refused trailing plus before a sibling item' => ["- q\n+\nx\n+\n- b\n", "<ul>\n  <li>q\n    x\n+\n  </li>\n  <li>b</li>\n</ul>"];
        yield 'refused trailing plus folds into an attached quote' => ["- q\n+\n> x\n+\n", "<ul>\n  <li>q\n    <blockquote><p>x\n+</p></blockquote>\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testFollowerOwnership(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
