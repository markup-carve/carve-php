<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\FenceAndContinuationLinter;
use PHPUnit\Framework\TestCase;

final class AttachedCodeFenceBlankTest extends TestCase
{
    public function testBlankInsideAttachedCodeFenceDoesNotEndIt(): void
    {
        $source = "- a\n  - +\n  ```\n  x\n\n  y\n  ```\n\n    # g\n";
        self::assertSame("<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>x\n\ny\n</code></pre>\n        <h1 id=\"g\">g</h1>\n      </li>\n    </ul>\n  </li>\n</ul>", (new CarveConverter())->convert($source));
        self::assertSame([], array_values(array_filter(
            (new FenceAndContinuationLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'unclosed-code-fence',
        )));
    }
}
