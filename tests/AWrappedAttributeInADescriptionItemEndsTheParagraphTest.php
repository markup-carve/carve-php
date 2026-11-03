<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A wrapped attribute at a nested item's content column in a description body is one construct
 * and leaves no paragraph for the next line to fold into (carve-php#4254, carve#3421). Expected HTML is the spec model's.
 */
final class AWrappedAttributeInADescriptionItemEndsTheParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'pack case ownership-2c66125f9d1c (#4254)' => [":: t\n: - a\n    {.c\n}\n    {.c\npara\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <p class=\"c\">{.c\npara</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'closer at the item column' => [":: t\n: - a\n    {.c\n    }\n    para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <p class=\"c\">para</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'closer below the description column' => [":: t\n: - a\n    {.c\n}\n    para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <p class=\"c\">para</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'three-line run' => [":: t\n: - a\n    {#i\n    .c\n    }\n    para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <p id=\"i\" class=\"c\">para</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'blank before the run' => [":: t\n: - a\n\n    {.c\n    }\n    para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li><p>a</p>\n        <p class=\"c\">para</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'ordered item' => [":: t\n: 1. a\n     {.c\n     }\n     para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>a\n        <p class=\"c\">para</p>\n      </li>\n    </ol>\n  </dd>\n</dl>"];
        yield 'inner item of a marker chain' => [":: t\n: - - a\n      {.c\n      }\n      para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li>a\n            <p class=\"c\">para</p>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'inner item of a marker chain, wider separator' => [":: t\n:  - - a\n       {.c\n        d}\n\n       para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li><p>a</p>\n            <p class=\"c\" d=\"\">para</p>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'outer item of a marker chain' => [":: t\n: - - a\n    {.c\n    }\n    para\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li>a</li>\n        </ul>\n        <p class=\"c\">para</p>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
    }

    #[DataProvider('documents')]
    public function testTheRunAttachesToTheNextBlock(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
