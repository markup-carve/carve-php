<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `: +` description after one a first-block `+` filled is its sibling and takes the block below it (carve-php#4306).
 */
class ASecondFirstBlockDescriptionTakesItsBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'paragraph, blank, quote' => [
                ":: t\n: +\nafter\n\n: +\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>after</dd>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'paragraph, quote' => [
                ":: t\n: +\nafter\n: +\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>after</dd>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'heading, quote' => [
                ":: t\n: +\n# H\n: +\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"H\">H</h1>\n  </dd>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'heading, blank, paragraph' => [
                ":: t\n: +\n# H\n\n: +\nz\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"H\">H</h1>\n  </dd>\n  <dd>z</dd>\n</dl>\n",
            ],
            'list, quote' => [
                ":: t\n: +\n- l\n: +\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>l</li>\n    </ul>\n  </dd>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'quoted, rule, quote' => [
                "> :: t\n> : +\n> ---\n> : +\n> > q\n",
                "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <hr>\n    </dd>\n    <dd>\n      <blockquote><p>q</p></blockquote>\n    </dd>\n  </dl>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
