<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AttachedParagraphKeepsFollowingItemColumnBlockTest extends TestCase
{
    public function testFollowingItemColumnBlockStaysInTheItem(): void
    {
        $source = "- a\n+\ntext\n  # h\n- c\n";

        self::assertSame(
            "<ul>\n  <li>a\n    text\n    <h1 id=\"h\">h</h1>\n  </li>\n  <li>c</li>\n</ul>",
            (new CarveConverter())->convert($source),
        );
    }
}
