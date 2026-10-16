<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line below a description body's column, but not at column 0, folds into
 * the paragraph the body leaves open (§24 S4), as it does under a list item
 * (markup-carve/carve-php#2210).
 */
class ABelowColumnLineFoldsIntoADescriptionTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function folds(): array
    {
        return [
            'after a paragraph that continued at the column' => [
                ":: t\n: a\n  b\n y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\nb\ny</dd>\n</dl>\n",
            ],
            'after a closed fence' => [
                ":: t\n: ```\n  b\n  ```\n  c\n y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>b\n</code></pre>\n    <p>c\ny</p>\n  </dd>\n</dl>\n",
            ],
            'inside a div' => [
                ":: t\n: :::\n  a\n y\n  :::\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <div>\n      <p>a\ny</p>\n    </div>\n  </dd>\n</dl>\n",
            ],
            'into an unclosed inline run' => [
                ":: t\n: a\n  ```\n  b\n y\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n<code>\nb\ny</code></dd>\n</dl>\n",
            ],
        ];
    }

    #[DataProvider('folds')]
    public function testTheLineFoldsIntoTheOpenParagraph(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testAMarkerBelowTheColumnFoldsIntoTheBody(): void
    {
        // PART 9 §24 C3 BELOW and §10 I2: lazy text, not a new list.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>a\nb\n- y</dd>\n</dl>\n",
            $this->html(":: t\n: a\n  b\n - y\n"),
        );
    }
}
