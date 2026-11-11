<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5982: names written in several cases fold to the first
 * spelling and position, with the value of the LAST write (PART 10 §1).
 */
class TheLastWrittenSpellingOfANameWinsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRuns(): array
    {
        return [
            '#a ID=b #c' => ['#a ID=b #c', 'id="c"'],
            '#a ID=b' => ['#a ID=b', 'id="b"'],
            'ID=b #a' => ['ID=b #a', 'ID="a"'],
            'ID=b #a ID=c' => ['ID=b #a ID=c', 'ID="c"'],
            '#a Id=b #c' => ['#a Id=b #c', 'id="c"'],
            '#a #c ID=b' => ['#a #c ID=b', 'id="b"'],
            'ID=b id=c' => ['ID=b id=c', 'ID="c"'],
            '#a id=b #c' => ['#a id=b #c', 'id="c"'],
            'title=a TITLE=b title=c' => ['title=a TITLE=b title=c', 'title="c"'],
            'ID=b title=t #a ID=c' => ['ID=b title=t #a ID=c', 'ID="c" title="t"'],
        ];
    }

    #[DataProvider('provideRuns')]
    public function testAnInlineRun(string $run, string $attrs): void
    {
        $this->assertSame('<p><a href="/u" ' . $attrs . '>x</a></p>', $this->render("[x](/u){{$run}}\n"));
    }

    #[DataProvider('provideRuns')]
    public function testABlockAttributeLine(string $run, string $attrs): void
    {
        $this->assertSame('<p ' . $attrs . '>para</p>', $this->render("{{$run}}\npara\n"));
    }

    #[DataProvider('provideRuns')]
    public function testAnImage(string $run, string $attrs): void
    {
        $this->assertSame('<p>y <img src="/p.png" alt="i" ' . $attrs . '></p>', $this->render("y ![i](/p.png){{$run}}\n"));
    }

    #[DataProvider('provideRuns')]
    public function testAReferenceDefinition(string $run, string $attrs): void
    {
        $this->assertSame('<p><a href="/u" ' . $attrs . '>x</a></p>', $this->render("[x][r]\n\n[r]: /u {{$run}}\n"));
    }

    #[DataProvider('provideRuns')]
    public function testAnAdmonition(string $run, string $attrs): void
    {
        $this->assertSame(
            "<aside class=\"admonition note\" {$attrs} aria-label=\"Note\">\n  <p>body</p>\n</aside>",
            $this->render("{{$run}}\n::: note\nbody\n:::\n"),
        );
    }

    private function render(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }
}
