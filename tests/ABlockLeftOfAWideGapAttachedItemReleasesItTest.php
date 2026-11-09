<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ABlockLeftOfAWideGapAttachedItemReleasesItTest extends TestCase
{
    public function testFenceLeftOfItemContentColumnOpensAtDocument(): void
    {
        $source = ":: t\n: +\n-   li\n   ```\n   x\n   ```\n+\np\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>li</li>\n    </ul>\n  </dd>\n</dl>\n<pre><code>x\n</code></pre>\n<p>+\np</p>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
