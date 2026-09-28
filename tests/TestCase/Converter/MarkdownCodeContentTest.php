<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownCodeContentTest extends TestCase
{
    public function testCodeContentSurvivesConversion(): void
    {
        $cases = [
            ["    a\n      \n    b", '<pre><code>a' . "\n  \nb\n" . '</code></pre>'],
            ["    a\n  \n    b", '<pre><code>a' . "\n\nb\n" . '</code></pre>'],
            ["    a\n\t  \n    b", '<pre><code>a' . "\n  \nb\n" . '</code></pre>'],
            ["``` foo\\+bar\nx\n```", '<pre><code class="language-foo+bar">x' . "\n" . '</code></pre>'],
            ["``` f&#111;o\nx\n```", '<pre><code class="language-foo">x' . "\n" . '</code></pre>'],
            ["# H\n    x", '<section id="H">' . "\n  <h1>H</h1>\n  <pre><code>x\n</code></pre>\n</section>"],
            ["H\n---\n    x", '<section id="H">' . "\n  <h2>H</h2>\n  <pre><code>x\n</code></pre>\n</section>"],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new MarkdownToCarve())->convert($source)), "\n"));
        }
    }
}
