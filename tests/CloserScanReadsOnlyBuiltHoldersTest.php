<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closer scan skips only what the inline parse builds: an attribute block
 * that attaches to nothing is text (CARVE-P4-002), the label half of a
 * reference pair takes no destination, and the combined token's walk skips the
 * same holders as the bare walk (carve-php#4217, #4218).
 */
final class CloserScanReadsOnlyBuiltHoldersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function scans(): array
    {
        return [
            'unattached block after text' => ['{*x{title="*}"} b*}', '<p><strong>x{title=“</strong>”} b*}</p>'],
            'unattached block after a space' => ['{_x {title="_}"} b_}', '<p><u>x {title=“</u>”} b_}</p>'],
            'unattached block at content start' => ['{/{title="/}"} b/}', '<p><em>{title=“</em>”} b/}</p>'],
            'unattached block after a comment' => ['{*a{#c#}{title="*}"} b*}', '<p><strong>a<span class="critic-comment">c</span>{title=“</strong>”} b*}</p>'],
            'unattached block after a cross-reference' => ['{=</#a>{title="=}"} b=}', '<p><mark>&lt;/#a&gt;{title=“</mark>”} b=}</p>'],
            'unattached block after a literal delimiter' => ['{~x*{title="~}"} b~}', '<p><s>x*{title=“</s>”} b~}</p>'],
            'block attached to code' => ['{*`c`{title="*}"} b*}', '<p><strong><code title="*}">c</code> b</strong></p>'],
            'block attached to a span' => ['{^[a]{title="^}"} b^}', '<p><sup><span title="^}">a</span> b</sup></p>'],
            'block attached to a bare pair' => ['{_*a*{title="_}"} b_}', '<p><u><strong title="_}">a</strong> b</u></p>'],
            'block attached to a braced child' => ['{*{_a_}{title="*}"} b*}', '<p><strong><u title="*}">a</u> b</strong></p>'],
            'block attached to an autolink' => ['{,<https://e.com>{title=",}"} b,}', '<p><sub><a href="https://e.com" title=",}">https://e.com</a> b</sub></p>'],
            'superscript owns its caret' => ['{^[a](u^}) b^}', '<p><sup><a href="u^}">a</a> b</sup></p>'],
            'reference pair label' => ['*[b][a](u*) c*', '<p><strong>[b][a](u</strong>) c*</p>'],
            'reference pair label with a title' => ['*[b][a](u "t*") c*', '<p><strong>[b][a](u “t</strong>”) c*</p>'],
            'image reference pair label' => ['*![i][a](u*) c*', '<p><strong>![i][a](u</strong>) c*</p>'],
            'collapsed reference pair' => ['*[b][](u*) c*', '<p><strong>[b][](u</strong>) c*</p>'],
            'third run is link text again' => ['*[b][a][c](u*) c*', '<p><strong>[b][a]<a href="u*">c</a> c</strong></p>'],
            'note starts no pair' => ['*[^n][a](u*) c*', '<p><strong>[^n]<a href="u*">a</a> c</strong></p>'],
            'braced reference pair label' => ['{*[b][a](u*}) c*}', '<p><strong>[b][a](u</strong>) c*}</p>'],
            'combined reference pair label' => ['/*[b][a](u*/) c*/', '<p><strong><em>[b][a](u</em></strong>) c*/</p>'],
            'superscript reference chain' => ['{^[b][a][c](u^}) c^}', '<p><sup>[b][a]<a href="u^}">c</a> c</sup></p>'],
            'editorial run keeps its slot' => ['{+[b][a](u+}) c+}', '<p><ins>[b][a](u+}) c</ins></p>'],
            'combined: code attributes' => ['/*`c`{title="*/"} b*/', '<p><strong><em><code title="*/">c</code> b</em></strong></p>'],
            'combined: closer after code' => ['/*a `c`*/ x', '<p><strong><em>a <code>c</code></em></strong> x</p>'],
            'combined: autolink body' => ['/*<https://e.com/*/> b*/', '<p><strong><em><a href="https://e.com/*/">https://e.com/*/</a> b</em></strong></p>'],
            'combined: autolink attributes' => ['/*<https://e.com>{title="*/"} b*/', '<p><strong><em><a href="https://e.com" title="*/">https://e.com</a> b</em></strong></p>'],
            'combined: cross-reference body' => ['/*</#a*/> b*/', '<p><strong><em>&lt;/#a*/&gt; b</em></strong></p>'],
            'combined: braced child' => ['/*{_a*/_} b*/', '<p><strong><em><u>a*/</u> b</em></strong></p>'],
            'combined: braced child attributes' => ['/*{_a_}{title="*/"} b*/', '<p><strong><em><u title="*/">a</u> b</em></strong></p>'],
            'combined: bare pair attributes' => ['/*_a_{title="*/"} b*/', '<p><strong><em><u title="*/">a</u> b</em></strong></p>'],
            'combined: editorial comment' => ['/*{#c*/#} b*/', '<p><strong><em><span class="critic-comment">c*/</span> b</em></strong></p>'],
            'combined: unattached block' => ['/*x{title="*/"} b*/', '<p><strong><em>x{title=“</em></strong>”} b*/</p>'],
            'editorial scan reads the block behind a child' => ['{-{_a_}{title="-}"} b-}', '<p><del><u>a</u>{title=“</del>”} b-}</p>'],
        ];
    }

    #[DataProvider('scans')]
    public function testCloserScan(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source . "\n")));
    }
}
