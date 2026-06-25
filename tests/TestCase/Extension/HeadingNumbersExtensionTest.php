<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\HeadingNumbersExtension;
use PHPUnit\Framework\TestCase;

class HeadingNumbersExtensionTest extends TestCase
{
    /**
     * @param string $source
     * @param array{minLevel?: int, label?: string, crossref?: string} $opts
     */
    private function html(string $source, array $opts = []): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingNumbersExtension(
            minLevel: $opts['minLevel'] ?? 1,
            label: $opts['label'] ?? 'Section',
            crossref: $opts['crossref'] ?? 'number-title',
        ));

        return trim($converter->convert($source));
    }

    public function testFullGoldenMatchesCarveJs(): void
    {
        $source = "# Title\n\n## Parsing\n\nSee </#Parsing> and </#Rendering>.\n\n### Tokens\n\n## Rendering\n\n{.unnumbered}\n## Changelog";
        $expected = <<<'HTML'
<section id="Title">
  <h1>Title</h1>
  <section id="Parsing">
    <h2><span class="section-number">1</span> Parsing</h2>
    <p>See <a href="#Parsing">Section 1 - Parsing</a> and <a href="#Rendering">Section 2 - Rendering</a>.</p>
    <section id="Tokens">
      <h3><span class="section-number">1.1</span> Tokens</h3>
    </section>
  </section>
  <section id="Rendering">
    <h2><span class="section-number">2</span> Rendering</h2>
  </section>
  <section id="Changelog">
    <h2 class="unnumbered">Changelog</h2>
  </section>
</section>
HTML;
        $this->assertSame($expected, $this->html($source, ['minLevel' => 2]));
    }

    public function testNumbersDottedPerLevel(): void
    {
        $out = $this->html("# A\n\n## B\n\n## C\n\n### D");
        $this->assertStringContainsString('<span class="section-number">1</span> A', $out);
        $this->assertStringContainsString('<span class="section-number">1.1</span> B', $out);
        $this->assertStringContainsString('<span class="section-number">1.2</span> C', $out);
        $this->assertStringContainsString('<span class="section-number">1.2.1</span> D', $out);
    }

    public function testGapFreeAcrossSkippedLevels(): void
    {
        $out = $this->html("# A\n\n### C");
        $this->assertStringContainsString('<span class="section-number">1</span> A', $out);
        $this->assertStringContainsString('<span class="section-number">1.1</span> C', $out);
        $this->assertStringNotContainsString('1.0', $out);
    }

    public function testUnnumberedSkippedAndDoesNotAdvance(): void
    {
        $out = $this->html("# A\n\n{.unnumbered}\n# Preface\n\n# B");
        $this->assertStringContainsString('<span class="section-number">1</span> A', $out);
        $this->assertStringContainsString('<span class="section-number">2</span> B', $out);
        $this->assertDoesNotMatchRegularExpression('/section-number">\d+<\/span> Preface/', $out);
    }

    public function testDoesNotNumberBlockquoteHeadings(): void
    {
        $out = $this->html("# A\n\n> # Quoted");
        $this->assertStringContainsString('<span class="section-number">1</span> A', $out);
        $this->assertStringNotContainsString('section-number">1.1', $out);
    }

    public function testCrossrefNumberOnly(): void
    {
        $out = $this->html("# Parsing\n\nSee </#Parsing>.", ['crossref' => 'number']);
        $this->assertStringContainsString('<a href="#Parsing">Section 1</a>', $out);
    }

    public function testCrossrefTitleLeavesText(): void
    {
        $out = $this->html("# Parsing\n\nSee </#Parsing>.", ['crossref' => 'title']);
        $this->assertStringContainsString('<a href="#Parsing">Parsing</a>', $out);
        $this->assertStringContainsString('<span class="section-number">1</span> Parsing', $out);
    }

    public function testLabelConfigurable(): void
    {
        $out = $this->html("# Parsing\n\nSee </#Parsing>.", ['label' => '§']);
        $this->assertStringContainsString('<a href="#Parsing">§ 1 - Parsing</a>', $out);
    }

    public function testExplicitLinkTextUnchanged(): void
    {
        $out = $this->html("# Parsing\n\n[my words](#Parsing).");
        $this->assertStringContainsString('<a href="#Parsing">my words</a>', $out);
    }

    public function testExplicitSameTitleLinkUnchanged(): void
    {
        $out = $this->html("# Parsing\n\n[Parsing](#Parsing).");
        $this->assertStringContainsString('<a href="#Parsing">Parsing</a>', $out);
        $this->assertStringNotContainsString('Section 1 - Parsing', $out);
    }

    public function testImplicitHeadingReferenceUnchanged(): void
    {
        $out = $this->html("# Parsing\n\nSee [Parsing][].");
        $this->assertStringContainsString('>Parsing</a>', $out);
        $this->assertStringNotContainsString('Section 1 - Parsing', $out);
    }

    public function testNoCrashOnFigure(): void
    {
        $out = $this->html("# A\n\n![alt](/img.png)\n^ A caption.\n\n## B");
        $this->assertStringContainsString('<span class="section-number">1</span> A', $out);
        $this->assertStringContainsString('<span class="section-number">1.1</span> B', $out);
    }

    public function testReservesNonHeadingExplicitId(): void
    {
        // A `{#A}` div reserves the id first; the heading dedupes to A-2.
        $out = $this->html("{#A}\n::: note\nx\n:::\n\n# A\n\nSee </#A-2>.");
        $this->assertStringContainsString('id="A-2"', $out);
        $this->assertStringContainsString('<a href="#A-2">Section 1 - A</a>', $out);
    }

    public function testIdempotentAcrossRepeatedRenders(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingNumbersExtension());
        $doc = $converter->parse("# A\n\n## B");
        $first = trim($converter->render($doc));
        $second = trim($converter->render($doc));
        $this->assertSame($first, $second);
        $this->assertSame(1, substr_count($second, 'section-number">1<'));
    }

    public function testDegradesWithoutExtension(): void
    {
        $out = trim((new CarveConverter())->convert("# Parsing\n\nSee </#Parsing>."));
        $this->assertStringNotContainsString('section-number', $out);
        $this->assertStringContainsString('<a href="#Parsing">Parsing</a>', $out);
    }
}
