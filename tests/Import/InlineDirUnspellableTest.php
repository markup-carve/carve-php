<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Import\HtmlImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-052, C2-M-074 (carve-php#6110): the importer keeps `dir` on an inline node, and attributes on a caption or
 * definition term or description; Carve source spells none of them there, so the writer reports each.
 */
final class InlineDirUnspellableTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dirInlines(): array
    {
        return [
            'span with content' => ['<p>a<span dir="rtl" id="i">x</span>b</p>', '/children/0/children/1/dir'],
            'empty bdo' => ['<p>a<bdo dir="rtl" class="x"></bdo>b</p>', '/children/0/children/1/dir'],
            'emphasis' => ['<p>a<em dir="ltr">x</em>b</p>', '/children/0/children/1/dir'],
            'link' => ['<p>a<a href="/u" dir="auto">x</a>b</p>', '/children/0/children/1/dir'],
            'code' => ['<p>a<code dir="rtl">x</code>b</p>', '/children/0/children/1/dir'],
            'image' => ['<p>a<img src="i.png" alt="z" dir="rtl">b</p>', '/children/0/children/1/dir'],
            'nested' => ['<p>a<strong><span dir="rtl">x</span></strong>b</p>', '/children/0/children/1/children/0/dir'],
            'term attrs' => ['<dl><dt class="x">t</dt><dd>d</dd></dl>', '/children/0/children/0/attrs'],
            'description lang' => ['<dl><dt>t</dt><dd lang="ar">d</dd></dl>', '/children/0/children/1/lang'],
            'table caption lang' => ['<table><caption lang="ar">x</caption><tr><td>c</td></tr></table>', '/children/0/caption/lang'],
            'figure caption attrs' => [
                '<figure><img src="i.png" alt="a"><figcaption id="f">x</figcaption></figure>',
                '/children/0/caption/attrs',
            ],
        ];
    }

    #[DataProvider('dirInlines')]
    public function testDirOnInlineIsReportedUnspellable(string $html, string $path): void
    {
        $result = HtmlImporter::import($html);
        $lost = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'field-unspellable'));
        $this->assertSame([$path], array_map(static fn ($d): ?string => $d->path, $lost));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spelledInlines(): array
    {
        return [
            'span id' => ['<p>a<span id="i">x</span>b</p>', "a[x]{#i}b\n"],
            'span lang' => ['<p>a<span lang="ar">x</span>b</p>', "a[x]{:ar}b\n"],
            'invalid dir is dropped' => ['<p>a<span dir="up" class="x">x</span>b</p>', "a[x]{.x}b\n"],
        ];
    }

    #[DataProvider('spelledInlines')]
    public function testSpelledAttributesStillImport(string $html, string $source): void
    {
        $result = HtmlImporter::import($html);
        $this->assertNotContains('field-unspellable', $result->codes());
        $this->assertSame($source, $result->source);
    }
}
