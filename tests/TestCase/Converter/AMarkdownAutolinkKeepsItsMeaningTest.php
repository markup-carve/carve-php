<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URI and email controls from https://spec.commonmark.org/0.31.2/#autolinks.
 * Example 606 has a separate, pre-existing mention interpretation difference.
 */
class AMarkdownAutolinkKeepsItsMeaningTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function examples(): array
    {
        /** @var list<array{example:int, source:string, html:string}> $rows */
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-autolink-fidelity.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases['CommonMark ' . $row['example']] = [$row['source'], $row['html']];
        }

        $cases['two-letter scheme'] = ['<ab:value>', '<p><a href="ab:value">ab:value</a></p>'];
        $cases['32-character scheme'] = ['<' . str_repeat('a', 32) . ':value>', '<p><a href="' . str_repeat('a', 32) . ':value">' . str_repeat('a', 32) . ':value</a></p>'];
        $cases['33-character scheme'] = ['<' . str_repeat('a', 33) . ':value>', '<p>&lt;' . str_repeat('a', 33) . ':value&gt;</p>'];
        $cases['an escaped opening bracket'] = ['\<https://example.com>', '<p>&lt;https://example.com&gt;</p>'];
        $cases['an escaped backslash before an autolink'] = [str_repeat(chr(92), 2) . '<https://example.com/\*>', '<p>\<a href="https://example.com/%5C*">https://example.com/\*</a></p>'];
        $cases['a code span'] = ['`<https://example.com/\*>`', '<p><code>&lt;https://example.com/\*&gt;</code></p>'];
        $cases['formatting inside a one-letter spelling'] = ['<m:*abc*>', '<p>&lt;m:<em>abc</em>&gt;</p>'];

        $cases['an autolink-shaped title'] = ['[a](/u "<https://x/\*>")', '<p><a href="/u" title="&lt;https://x/*&gt;">a</a></p>'];
        $cases['an HTML comment'] = ['text <!-- <https://x/\*> -->', '<p>text <!-- <https://x/\*> --></p>'];
        $cases['an autolink inside a link label'] = ['[<https://x/\*>](/u)', '<p>[<a href="https://x/%5C*">https://x/\*</a>](/u)</p>'];
        $cases['an escaped one-letter spelling'] = ['\<m:x>', '<p>&lt;m:x&gt;</p>'];
        $cases['an entity in the label and destination'] = ['<https://x/\?a=&amp;>', '<p><a href="https://x/%5C?a=&amp;">https://x/\?a=&amp;</a></p>'];

        $cases['an escaped bracket in a pointy destination'] = ['[a](</u\\>>)', '<p><a href="/u%3E">a</a></p>'];
        $cases['an escaped HTML tag closer'] = ['a <br\\> b', '<p>a &lt;br&gt; b</p>'];
        $cases['an escaped autolink in a link label'] = ['[\\<https://x>](/u)', '<p><a href="/u">&lt;https://x&gt;</a></p>'];
        $cases['a code span in a link label'] = ['[`<https://x>`](/u)', '<p><a href="/u"><code>&lt;https://x&gt;</code></a></p>'];
        $cases['a pointy link destination'] = ['[a](<https://x>)', '<p><a href="https://x">a</a></p>'];
        $cases['an entity without a backslash'] = ['<https://x/?a=&amp;>', '<p><a href="https://x/?a=&amp;">https://x/?a=&amp;</a></p>'];
        $cases['nested protected spans in a label'] = ['<https://x/\\`a`>', '<p><a href="https://x/%5C%60a%60">https://x/\\`a`</a></p>'];

        return $cases;
    }

    #[DataProvider('examples')]
    public function testImportedSourceKeepsTheLinkAndItsLabel(string $markdown, string $html): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame($html, trim((new CarveConverter())->convert($carve)));
    }
}
