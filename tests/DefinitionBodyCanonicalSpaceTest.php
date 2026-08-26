<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionBodyCanonicalSpaceTest extends TestCase
{
    public function testOneSpaceIsAcceptedAndCanonical(): void
    {
        self::assertSame(":: x\n: y\n", CarveConverter::toCarve(":: x\n: y\n"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalForms(): iterable
    {
        yield 'two spaces' => [":: x\n:  y\n", ":: x\n: y\n"];
        yield 'four spaces' => [":: x\n:    y\n", ":: x\n: y\n"];
        yield 'continuation' => [":: x\n:  y\n\n   > q\n", ":: x\n: y\n\n  > q\n"];
        yield 'fenced block' => [
            ":: x\n:  y\n   ```\n   a\n\n   b\n   ```\n",
            ":: x\n: y\n\n  ```\n  a\n\n  b\n  ```\n",
        ];
    }

    #[DataProvider('canonicalForms')]
    public function testWiderSeparatorIsNarrowedWithItsBody(string $source, string $canonical): void
    {
        self::assertSame($canonical, CarveConverter::toCarve($source));
        $converter = CarveConverter::create();
        self::assertSame($converter->convert($source), $converter->convert($canonical));
        self::assertSame($canonical, CarveConverter::toCarve($canonical));
    }

    public function testHtmlImporterUsesTheCanonicalSeparator(): void
    {
        $imported = (new HtmlToCarve())->convert('<dl><dt>x</dt><dd>y</dd></dl>');

        self::assertSame(":: x\n: y\n", $imported);
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }
}
