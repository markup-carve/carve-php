<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unresolved footnote reference keeps its attribute run as text, like an
 * unresolved reference link (carve#4816, option A).
 */
class AnUnresolvedFootnoteKeepsItsAttributeRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'reproduction' => ['x[^n]{.c} and [t][m]{.c}', '<p>x[^n]{.c} and [t][m]{.c}</p>'],
            'escaped, not reparsed' => ['x[^n]{a="<b>"}{.c}', '<p>x[^n]{a="&lt;b&gt;"}{.c}</p>'],
            'heading' => ['# H[^n]{.c}', "<section id=\"H\">\n  <h1>H[^n]{.c}</h1>\n</section>"],
            'table cell' => ['| a[^n]{.c} |', "<table>\n  <tbody>\n    <tr><td>a[^n]{.c}</td></tr>\n  </tbody>\n</table>"],
            'link text' => ['[a[^n]{.c}](/u)', '<p><a href="/u">a[^n]{.c}</a></p>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheRunRendersAsText(string $source, string $html): void
    {
        self::assertSame($html, trim(CarveConverter::create()->convert($source . "\n")));
    }

    public function testAResolvedReferenceStillTakesTheRunAsAttributes(): void
    {
        self::assertStringContainsString('role="doc-noteref" class="c"', CarveConverter::create()->convert("x[^n]{.c}\n\n[^n]: body\n"));
    }

    public function testAProfileThatStripsTheDefinitionKeepsTheRun(): void
    {
        $profile = Profile::full()->denyBlock(['footnote'])->onDisallowed(Profile::ACTION_STRIP);

        self::assertSame('<p>x[^n]{.c}</p>', trim((new CarveConverter(profile: $profile))->convert("[^n]: body\n\nx[^n]{.c}\n")));
    }

    public function testTheTextTargetsKeepTheRun(): void
    {
        self::assertSame("x[^n]{.c}\n", CarveConverter::plainText()->convert("x[^n]{.c}\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function written(): array
    {
        return [
            'two blocks' => ["x[^n]{a=\"<b>\"}{.c}\n"],
            'beside a cell hard break' => ["x[^n]{.a}{.b}\n\n| a\\ | b |\n"],
            'in a table cell' => ["| a[^n]{title=\"x\\|y\"} |\n"],
        ];
    }

    #[DataProvider('written')]
    public function testTheWriterKeepsTheRunAsTyped(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($source, $formatted);
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }
}
