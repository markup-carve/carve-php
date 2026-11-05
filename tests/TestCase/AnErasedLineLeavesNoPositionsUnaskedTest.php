<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The erasure pass places nodes by block lines only, so a parse that asked for no positions carries none,
 * inline titles and captions included (carve#5184).
 */
final class AnErasedLineLeavesNoPositionsUnaskedTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        yield 'an admonition title' => ["::: note \"Careful\"\nVisible body.\n:::\n\n::: note \"Careful\"\n%% hidden\n:::\n"];

        yield 'a table caption' => ["*[A]: x\n\n{#t}\n| a |\n^ A #: c\n\nSee </#t>.\n"];
    }

    public function testAnErasedLineAfterALoneCarriageReturnIsStillErased(): void
    {
        $converter = new CarveConverter();

        self::assertSame(trim($converter->convert("a\n%% hidden\nb\n")), trim($converter->convert("a\r%% hidden\rb\r")));
    }

    #[DataProvider('sources')]
    public function testNoPositionIsWritten(string $source): void
    {
        $ast = json_encode((new AstCodec())->encode((new CarveConverter())->parse($source)), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('"pos"', $ast);
    }
}
