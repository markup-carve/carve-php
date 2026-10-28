<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoResolverCodeIncludeInContainerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'list item' => ["- {{ c @code }}\n", '<li>{{ c @code }}</li>'];
        yield 'quote' => ["> {{ c @code }}\n", '<blockquote><p>{{ c @code }}</p></blockquote>'];
        yield 'code language' => ["- {{ c @code:txt }}\n", '<li>{{ c @code:txt }}</li>'];
        yield 'inline host keeps its mention' => ["x {{ c @code }} y\n", '<span class="mention"><strong>@code</strong></span>'];
    }

    #[DataProvider('cases')]
    public function testNoResolverCodeIncludeAtHostBoundary(string $source, string $fragment): void
    {
        self::assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }
}
