<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A payload line of a `%%%` span opened on a description lead keeps the columns
 * past the body column (PART 9 §28, carve#4988); it is not paragraph text.
 */
class ADescriptionLeadSpansPayloadKeepsItsColumnsTest extends TestCase
{
    /**
     * @return array<string> The content of every comment node, in tree order.
     */
    private function payloads(string $source): array
    {
        $found = [];
        $walk = function (Node $node) use (&$walk, &$found): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Comment) {
                    $found[] = $child->getContent();
                }
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $found;
    }

    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function leadSpans(): array
    {
        return [
            'document' => [":: u\n: %%%\n  n\n   x\n%%%\n", ["n\n x"]],
            'document, deeper and back' => [":: u\n: %%%\n  n\n    x\n   y\n  %%%\n", ["n\n  x\n y"]],
            'document, longer fence' => [":: u\n: %%%%\n  n\n   x\n  %%%%\n", ["n\n x"]],
            'document, opener tail' => [":: u\n: %%% t\n  n\n   x\n%%%\n", ["t\nn\n x"]],
            'in an item' => ["- a\n  :: u\n  : %%%\n    n\n     x\n    %%%\n", ["n\n x"]],
            'in a quote' => ["> :: u\n> : %%%\n>   n\n>    x\n> %%%\n", ["n\n x"]],
            'two items deep' => ["- a\n  - b\n    :: u\n    : %%%\n      n\n        x\n%%%\n", ["n\n  x"]],
            'a reference definition line' => [":: u\n: %%%\n  a\n     [r]: /u\n     z\n%%%\n", ["a\n   [r]: /u\n   z"]],
            'an attribute line' => [":: u\n: %%%\n  a\n   {.x}\n%%%\n", ["a\n {.x}"]],
            'a list marker line' => [":: u\n: %%%\n  a\n   - li\n  %%%\n", ["a\n - li"]],
        ];
    }

    /**
     * A code fence opener in the payload opens no code block in the description.
     *
     * @return array<string, array{string, string}>
     */
    public static function fenceInPayload(): array
    {
        return [
            'at the body column' => [":: u\n: %%%\n  a\n  ```\n  b\n  %%%\nz\n", "a\n```\nb"],
            'past the body column' => [":: u\n: %%%\n  a\n  ```\n    b\n  %%%\nz\n", "a\n```\n  b"],
        ];
    }

    #[DataProvider('fenceInPayload')]
    public function testAFenceInThePayloadStaysPayload(string $source, string $payload): void
    {
        $this->assertSame([$payload], $this->payloads($source));
        $this->assertSame(
            "<dl>\n  <dt>u</dt>\n  <dd></dd>\n</dl>\n<p>z</p>",
            trim((new CarveConverter())->convert($source)),
        );
    }

    /**
     * @param string $source
     * @param array<string> $payloads
     */
    #[DataProvider('leadSpans')]
    public function testThePayloadKeepsItsColumnsPastTheBody(string $source, array $payloads): void
    {
        $this->assertSame($payloads, $this->payloads($source));
    }

    public function testTheSpanStaysInvisible(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>u</dt>\n  <dd></dd>\n</dl>",
            trim((new CarveConverter())->convert(":: u\n: %%%\n  n\n   x\n%%%\n")),
        );
    }
}
