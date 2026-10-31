<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MalformedDelimitedSourceOutsideSelectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function malformedSources(): array
    {
        return [
            'csv after selected line' => ['csv', "h\nb\n\"x\n", 'b'],
            'csv before selected line' => ['csv', "h\"oops\nb\n", 'b'],
            'tsv after selected line' => ['tsv', "h\nb\n\"x\n", 'b'],
        ];
    }

    #[DataProvider('malformedSources')]
    public function testTheWholeFileDecidesWhetherASelectedDelimitedSourceBecomesATable(string $format, string $file, string $selected): void
    {
        $source = "{table}\n{{ m.{$format} @code:{$format} @lines:2-2 }}\n";
        $resolver = new class ($format, $file) implements IncludeResolverInterface {
            public function __construct(private string $format, private string $file)
            {
            }

            public function resolve(string $path, IncludeContext $context): ?string
            {
                return $path === "m.{$this->format}" ? $this->file : null;
            }
        };
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $expanded = (new IncludeExpander($resolver, source: $source))->transform($document);

        $this->assertSame("<pre table=\"\"><code class=\"language-{$format}\">" . htmlspecialchars($selected, ENT_NOQUOTES) . "\n</code></pre>\n", $converter->render($expanded));
    }
}
