<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence left open in a list item inside a description ends with the item
 * when a line below the description's column closes it (PART 1 S4). The line
 * does not open a second fence in the description, and does not join the
 * item's fence as content.
 */
class AFenceInADescriptionsListItemClosesInsideTheItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $item = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>x\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<p>x</p>";
        $nested = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ul>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>\n<p>x</p>";

        return [
            'opener past the item column' => [":: t\n: - x\n     ```\nx\n", $item],
            'opener past the item column, blank before the line' => [":: t\n: - x\n    ~~~\n\nx\n", $item],
            'closer of the inner item opens a fence in the outer one' => [":: t\n: - - ```\n     ```\nx\n", $nested],
            'same, with a wider body column' => [":: t\n:  - - ```\n      ```\nx\n", $nested],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFenceEndsWithTheItem(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
