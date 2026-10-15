<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 E2a and CARVE-P3-001 (ruled 2026-10-15): an autolink, a cross-reference
 * and a comment are atomic, so no delimiter, arrow or bracket outside one reads inside it.
 */
class AnAutolinkACrossReferenceOrACommentIsAtomicTest extends TestCase
{
    use ScalingGuardTrait;

    #[DataProvider('cases')]
    public function testTheConstructStaysWhole(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source . "\n")));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'autolink against the arrow that ends it' => ['{~a <https://x~>y> b~}', '<p><s>a <a href="https://x~">https://x~</a>y&gt; b</s></p>'],
            'later arrow outside the autolink' => ['{~a <https://x~>y~>z b~}', '<p><del>a <a href="https://x~">https://x~</a>y</del><ins>z b</ins></p>'],
            'cross-reference against the arrow that ends it' => ['{~a </#x~> b~}', '<p><s>a &lt;/#x~&gt; b</s></p>'],
            'address that cannot end in a tilde' => ['{~a <me@x.org~>y> b~}', '<p><del>a &lt;me@x.org</del><ins>y&gt; b</ins></p>'],
            'editorial comment hides an insertion closer' => ['{+a {#x+}#} b+}', '<p><ins>a <span class="critic-comment">x+}</span> b</ins></p>'],
            'editorial comment hides a deletion closer' => ['{-a {#x-}#} b-}', '<p><del>a <span class="critic-comment">x-}</span> b</del></p>'],
            'delimited comment hides an insertion closer' => ['{+a {%x+}%} b+}', '<p><ins>a  b</ins></p>'],
            'comment opened first closes at its own closer' => ['{#a </#x#}> b#}', '<p><span class="critic-comment">a &lt;/#x</span>&gt; b#}</p>'],
            'link label around a cross-reference' => ['[a </#x]> b](u)', '<p><a href="u">a &lt;/#x]&gt; b</a></p>'],
            'span label around a cross-reference' => ['[a </#x]> b]{.c}', '<p><span class="c">a &lt;/#x]&gt; b</span></p>'],
            'image label around a cross-reference' => ['![a </#x]> b](u)', '<img src="u" alt="a &lt;/#x]&gt; b">'],
            'cross-reference shaped like a destination' => ['[a </#x](u)> b](v)', '<p><a href="v">a &lt;/#x](u)&gt; b</a></p>'],
            'link label around a delimited comment' => ['[a {%x]%} b](u)', '<p><a href="u">a  b</a></p>'],
            'inline note around a cross-reference' => ['^[a </#x]> b]', "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a &lt;/#x]&gt; b<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'cross-reference against an arrow before more text' => ['{~a </#x~>y> b~}', '<p><s>a &lt;/#x~&gt;y&gt; b</s></p>'],
        ];
    }

    #[Group('scaling')]
    #[DataProvider('shapes')]
    public function testTheSkipsScaleLinearly(string $fragment, string $suffix): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), $fragment, $suffix, "'{$fragment}'");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'cross-references holding a bracket inside open labels' => ['[a </#x]> ', ' ](u)'],
            'substitutions whose only arrow ends an autolink' => ['{~a <https://x~>y ', ' ~}'],
            'insertions around delimited comments holding the closer' => ['{+a {%x+}%} ', ' +}'],
            'unclosed cross-reference openers inside labels' => ['[</#x', '](u)'],
        ];
    }
}
