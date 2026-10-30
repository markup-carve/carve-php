<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paragraph text starts past a marker's whole space run (PART 0 S3), and a form
 * feed is content, not whitespace, at every indentation (PART 2, rule A).
 */
final class AnItemLeadRunAndAFormFeedHeadingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'second space in a loose item' => ["- a\n\n-  b", "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>"];
        yield 'third space in a loose item' => ["- a\n\n-   b", "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>"];
        yield 'indented form feed heading' => [" # \f", "<section id=\"s\">\n  <h1>\f</h1>\n</section>"];
        yield 'deeper form feed heading' => ["  ## \f", "<section id=\"s\">\n  <h2>\f</h2>\n</section>"];
        yield 'quoted form feed heading' => [">  # \f", "<blockquote>\n  <h1 id=\"s\">\f</h1>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source . "\n"));
    }
}
