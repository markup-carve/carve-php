<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionContinuationOwnerTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function continuations(): iterable
    {
        yield 'blank-separated term and body' => [":: t\n\n: d\n+\nx\n", "<dd>\n    <p>d</p>\n    <p>x</p>\n  </dd>"];
        yield 'two separator blanks' => [":: t\n\n\n: d\n+\nx\n", "<dd>\n    <p>d</p>\n    <p>x</p>\n  </dd>"];
        yield 'later list item first block' => [":: t\n: - a\n  - +\n  > b\n", "<li>\n        <blockquote><p>b</p></blockquote>\n      </li>"];
        yield 'later ordered item first block' => [":: t\n: 1. a\n  2. +\n  > b\n", "<li>\n        <blockquote><p>b</p></blockquote>\n      </li>"];
        yield 'below child column stays literal' => [":: t\n: - a\n   - +\n   > b\n", "<li>a\n- +\n&gt; b</li>"];
        yield 'deeper first block owns quote' => [":: t\n: - a\n    - +\n    > b\n", "<li>\n            <blockquote><p>b</p></blockquote>\n          </li>"];
        yield 'marker-line list first block' => [":: t\n: - +\nx\n", "<li>+\nx</li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'marker-line list takes document heading' => [":: t\n: - +\n# H\n", "<li>+</li>\n    </ul>\n  </dd>\n</dl>\n<section id=\"H\">"];
        yield 'marker-line list takes document quote' => [":: t\n: - +\n> b\n", "<li>+</li>\n    </ul>\n  </dd>\n</dl>\n<blockquote><p>b</p></blockquote>"];
    }

    #[DataProvider('continuations')]
    public function testDescriptionOwnsItsContinuation(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
