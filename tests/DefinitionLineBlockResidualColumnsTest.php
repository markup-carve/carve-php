<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionLineBlockResidualColumnsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'consecutive lines retain their residual columns' => [
            ":: u\n: ::: |\n   x\n   y\nb\n",
            "<dl>\n  <dt>u</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>&nbsp;x<br>\n&nbsp;y<br>\nb</p>\n    </div>\n  </dd>\n</dl>",
        ];

        yield 'literal looking lines retain stanza ownership' => [
            ":: u\n: ::: |\n  ---\n  ---\nb\n",
            "<dl>\n  <dt>u</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>—<br>\n—<br>\nb</p>\n    </div>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
