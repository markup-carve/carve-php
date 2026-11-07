<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A host reads its next block at its content column after the one attached block ends (carve#5426).
 */
final class AAttachedHostResumesAtContentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item with a sublist' => [
            "- +\n```\n```\n\n  - x\n",
            "<ul>\n  <li>\n    <pre><code></code></pre>\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'description with a quote' => [
            ":: t\n: +\n```\n```\n\n  > q\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code></code></pre>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testHostResumes(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
