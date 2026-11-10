<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AColonTextCannotOpenACommentSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'colon text' => ["- a\n  : x\n\n  : %%%\n%%%\nc\n", "<ul>\n  <li><p>a\n: x</p>\n    <p>:</p>\n  </li>\n</ul>"];
        yield 'term ended by deeper text' => ["- a\n  :: t\n    deep\n  : d\n\n  : %%%\n%%%\nc\n", "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <p>deep\n: d</p>\n    <p>:</p>\n  </li>\n</ul>"];
        yield 'live term still opens a body' => ["- a\n  :: t\n  : %%%\n  %%%\nc\n", "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>c</p>"];
        yield 'content within a live body keeps the term' => ["- a\n  :: t\n  : d\n    deep\n  : %%%\n%%%\nc\n", "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>d\ndeep</dd>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>c</p>"];
    }

    #[DataProvider('examples')]
    public function testColonLeadRequiresALiveTerm(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
