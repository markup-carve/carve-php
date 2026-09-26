<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A deeper body line, a blank and a line at the content column stay in the
 * open fence or div of a loose item (markup-carve/carve-php#2507).
 */
class AnOpenFenceInALooseItemKeepsItsBodyPastABlankTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function containerProvider(): array
    {
        $code = "<ul>\n  <li>a\n    <pre><code>x\n    i\n\ny\n</code></pre>\n  </li>\n</ul>";

        return [
            'backtick fence' => ["- a\n\n  ```\n  x\n      i\n\n  y\n  ```\n", $code],
            'tilde fence' => ["- a\n\n  ~~~\n  x\n      i\n\n  y\n  ~~~\n", $code],
            'colon div' => [
                "- a\n\n  :::\n  x\n\n      i\n\n  y\n  :::\n",
                "<ul>\n  <li>a\n    <div>\n      <p>x</p>\n      <p>i</p>\n      <p>y</p>\n    </div>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testBodyStaysInTheContainer(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));

        $carve = CarveConverter::toCarve($src);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($carve), "\n"));
    }
}
