<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A nested item's captioned child is attached with `+`, not a blank line: since
 * carve#5015 a blank before a direct child block loosens the list.
 */
class TheWriterAttachesANestedItemsChildWithAPlusTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'captioned quote, depth 2' => ["- x\n  - text\n    > q\n\n    ^ cap\n  - c\n"],
            'captioned fence, depth 3' => ["- a\n  - b\n    - text\n      ```\n      code\n      ```\n\n      ^ cap\n    - c\n"],
            'captioned quote in an ordered item' => ["- x\n  1. text\n     > q\n\n     ^ cap\n\n  after\n"],
            'captioned quote under a quote host' => ["> - x\n>   - text\n>     > q\n>\n>     ^ cap\n>   - c\n"],
            'captioned table, depth 2' => ["- x\n  - text\n    | a |\n\n    ^ cap\n  - c\n"],
            'captioned quote, then a fence' => ["- x\n  - start\n    > q\n\n    ^ cap\n    ```\n    c\n    ```\n  - end\n"],
            'captioned quote, then a line comment' => ["- x\n  - text\n    > q\n\n    ^ cap\n    %% k\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testFmtKeepsTheListTight(string $source): void
    {
        $formatted = $this->fmt($source);

        $this->assertSame($this->html($source), $this->html($formatted), $formatted);
        $this->assertSame($formatted, $this->fmt($formatted));
    }

    public function testTheChildIsWrittenAfterAPlusAtTheItemsMarkerColumn(): void
    {
        $this->assertSame(
            "- x\n  - text\n  +\n  > q\n  ^ cap\n  - c\n",
            $this->fmt("- x\n  - text\n    > q\n\n    ^ cap\n  - c\n"),
        );
    }
}
