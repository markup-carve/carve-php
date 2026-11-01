<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TermInUnclosedDivKeepsLazyLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'list body' => ["- a\n  :::\n   :: b\na\n: f\n", "<ul>\n  <li>a\n    <div>\n      <dl>\n        <dt>b\na</dt>\n      </dl>\n    </div>\n  </li>\n</ul>\n<p>: f</p>"];
        yield 'marker line' => ["- :::\n  :: b\na\n: f\n", "<ul>\n  <li>\n    <div>\n      <dl>\n        <dt>b\na</dt>\n      </dl>\n    </div>\n  </li>\n</ul>\n<p>: f</p>"];
        yield 'description body' => [":: t\n: :::\n  :: b\na\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n      <dl>\n        <dt>b\na</dt>\n      </dl>\n    </div>\n  </dd>\n</dl>"];
    }

    #[DataProvider('hosts')]
    public function testPlainLazyLineStaysInTheTerm(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
