<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ABlockAfterAReleasedListStaysInItsNestedDescriptionTest extends TestCase
{
    public function testAFenceAtTheMiddleDescriptionsBodyColumnStaysThere(): void
    {
        $source = ":: t\n: :: t\n  : :: t\n     - x\n    ```\n    code\n    ```\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <dl>\n          <dt>t</dt>\n        </dl>\n"
            . "        <ul>\n          <li>x</li>\n        </ul>\n        <pre><code>code\n</code></pre>\n      </dd>\n    </dl>\n  </dd>\n</dl>";

        // carve-php#4681: releasing the list sent the fence to the outermost description.
        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    public function testFmtOutputReadsBackTheSame(): void
    {
        $source = ":: t\n: :: t\n  : :: t\n     - x\n    ```\n    code\n    ```\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
