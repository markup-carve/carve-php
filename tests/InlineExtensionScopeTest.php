<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineExtensionScopeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function extensionBodies(): array
    {
        return [
            'strong in strong' => ['*:x[*b*]*', '<p><strong><span class="ext-x"><strong>b</strong></span></strong></p>'],
            'emphasis in emphasis' => ['/:x[/i/]/', '<p><em><span class="ext-x"><em>i</em></span></em></p>'],
            'underline in underline' => ['_:x[_u_]_', '<p><u><span class="ext-x"><u>u</u></span></u></p>'],
            'strike in strike' => ['~:x[~s~]~', '<p><s><span class="ext-x"><s>s</s></span></s></p>'],
            'first closing bracket ends outer extension' => ['*:x[:y[*b*]]*', '<p><strong><span class="ext-x">:y[<strong>b</strong></span>]</strong></p>'],
            'empty extension' => ['*:x[]*', '<p><strong><span class="ext-x"></span></strong></p>'],
            'combined span skips extension closer' => ['/*:x[a*/ b]*/', '<p><strong><em><span class="ext-x">a*/ b</span></em></strong></p>'],
            'combined span contains combined extension content' => ['/*:x[/*b*/]*/', '<p><strong><em><span class="ext-x"><strong><em>b</em></strong></span></em></strong></p>'],
            'combined span keeps a plain bracket run' => ['/*a [b*/ c] d*/', '<p><strong><em>a [b*/ c] d</em></strong></p>'],
            'combined span keeps an escaped extension run' => ['/*a \:x[b*/ c] d*/', '<p><strong><em>a :x[b*/ c] d</em></strong></p>'],
            'combined span keeps a symbol followed by a run' => ['/*x :a:b[y*/] z*/', '<p><strong><em>x :a:b[y*/] z</em></strong></p>'],
            'extension stops at its first closing bracket' => ['/*a :x[[b]*/ c] d*/', '<p><strong><em>a <span class="ext-x">[b</span></em></strong> c] d*/</p>'],
            'escaped bracket does not hide closer' => ['/*a \[b*/ c] d*/', '<p><strong><em>a [b</em></strong> c] d*/</p>'],
            'link destination hides closer' => ['/*[a](u*/) b*/', '<p><strong><em><a href="u*/">a</a> b</em></strong></p>'],
            'span attributes hide closer' => ['/*[a]{title="*/"} b*/', '<p><strong><em><span title="*/">a</span> b</em></strong></p>'],
            'extension attributes hide closer' => ['/*:x[a]{title="*/"} b*/', '<p><strong><em><span class="ext-x" title="*/">a</span> b</em></strong></p>'],
            'link attributes hide closer' => ['/*[a](u){title="*/"} b*/', '<p><strong><em><a href="u" title="*/">a</a> b</em></strong></p>'],
            'escaped closer stays inside combined span' => ['/*a \*/ b*/', '<p><strong><em>a */ b</em></strong></p>'],
            'single strong ends after first extension bracket' => ['*a :x[[b]* c]*', '<p><strong>a <span class="ext-x">[b</span></strong> c]*</p>'],
        ];
    }

    #[DataProvider('extensionBodies')]
    public function testExtensionBodyHasItsOwnInlineScope(string $source, string $expected): void
    {
        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    public function testWriterKeepsRepeatedKindsSeparatedByAnExtension(): void
    {
        foreach (['*:x[*b*]*', '{+:x[{+*b*+}]+}', '{-:x[{-*b*-}]-}'] as $source) {
            $written = CarveConverter::toCarve($source);
            self::assertSame($source . "\n", $written);
            self::assertSame($written, CarveConverter::toCarve($written));
        }
    }
}
