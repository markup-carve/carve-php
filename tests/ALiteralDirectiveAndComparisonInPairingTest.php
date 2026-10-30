<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline pairing against PART 9 section 9, CARVE-P6-001 and CARVE-P9-041.
 */
final class ALiteralDirectiveAndComparisonInPairingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        yield 'not equal' => ['=a != b=', '<p><mark>a ≠ b</mark></p>'];
        yield 'less or equal' => ['=a <= b=', '<p><mark>a ≤ b</mark></p>'];
        yield 'greater or equal' => ['=a >= b=', '<p><mark>a ≥ b</mark></p>'];
        yield 'extra equals closes' => ['=a !== b=', '<p><mark>a ≠</mark> b=</p>'];
        yield 'an arrow gives its equals to a closer' => ['=a <== b=', '<p><mark>a &lt;</mark>= b=</p>'];
        yield 'escaped bang closes' => ['=Done\\!=', '<p><mark>Done!</mark></p>'];
        yield 'substitution old half' => ['{~%% b~>x~}', '<p><del>%% b</del><ins>x</ins></p>'];
        yield 'substitution new half' => ['{~x~>%% b~}', '<p><del>x</del><ins>%% b</ins></p>'];
        yield 'bare pair around a literal' => ['*{{ `@lines:1 }}ter*', '<p><strong>{{ `@lines:1 }}ter</strong></p>'];
        yield 'forced pair around a literal' => ['{+{{ `@lines:1 }}ter+}', '<p><ins>{{ `@lines:1 }}ter</ins></p>'];
        yield 'braced strong around a literal' => ['{*a {{ `@code }} b*}', '<p><strong>a {{ `@code }} b</strong></p>'];
        yield 'bracketed span around a literal' => ['[{{ [ @lines:0 }}]{.x}', '<p><span class="x">{{ [ @lines:0 }}</span></p>'];
        yield 'link around a literal' => ['[{{ `@lines:1 }}](u)', '<p><a href="u">{{ `@lines:1 }}</a></p>'];
    }

    #[DataProvider('runs')]
    public function testRun(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source . "\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function highlightsBesideAComparison(): iterable
    {
        yield 'content ends in bang' => ['{=Done!=}'];
        yield 'content ends in less' => ['{=a<=}'];
        yield 'content ends in greater' => ['{=a>=}'];
        yield 'bang before the opener' => ['!{=a=}'];
        yield 'less before the opener' => ['<{=a=}'];
        yield 'an arrow in the content' => ['{=a <== b=}'];
    }

    #[DataProvider('highlightsBesideAComparison')]
    public function testTheWriterKeepsTheHighlight(string $source): void
    {
        $document = (new CarveConverter())->parse($source . "\n");
        $written = (new CarveRenderer())->render($document);

        $this->assertSame((new CarveConverter())->convert($source . "\n"), (new CarveConverter())->convert($written));
    }

    public function testTheImporterKeepsAHighlightEndingInABang(): void
    {
        $written = (new MarkdownToCarve(convertHighlight: true))->convert('==Done!==');

        $this->assertSame('<p><mark>Done!</mark></p>', (new CarveConverter())->convert($written));
    }
}
