<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ASummaryTitleKeepsLinkTitleQuotesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function quotedTitles(): array
    {
        return [
            'link in a paragraph' => ['<p><a href="url" title="tip">one</a></p>', '[one](url "tip")', '<a href="url" title="tip">one</a>'],
            'several blocks' => ['<p><a href="url" title="tip">one</a></p><p>two</p>', "[one](url \"tip\")\n\ntwo", '<a href="url" title="tip">one</a>'],
            'direct link' => ['<a href="url" title="tip">one</a>', '[one](url "tip")', '<a href="url" title="tip">one</a>'],
            'nested formatting' => ['<strong><a href="url" title="tip">one</a></strong>', '*[one](url "tip")*', '<strong><a href="url" title="tip">one</a></strong>'],
            'image with no text content' => ['<img src="img.png" alt="one" title="tip">', '![one](img.png "tip")', '<img src="img.png" alt="one" title="tip">'],
        ];
    }

    #[DataProvider('quotedTitles')]
    public function testAnUnwritableTitleKeepsItsContentAndReportsItsLabel(string $summary, string $source, string $rendered): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $converter = new HtmlToCarve(importMode: $mode);
            $html = '<details><summary>' . $summary . '</summary>b</details>';
            $result = $converter->convertWithReport($html);
            $body = $mode === 'roundtrip' ? '`<summary>' . $summary . '</summary>`{=html}' : $source;
            $this->assertSame("::: details\n" . $body . "\n\nb\n:::\n", $result->value, $mode);
            $this->assertStringContainsString($rendered, (new CarveConverter())->convert($result->value), $mode);
            $summaryRows = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => $row['path'] === '/details[1]/summary[1]'));
            $this->assertCount(1, $summaryRows, $mode);
            $this->assertSame($mode === 'roundtrip' ? 'raw-preserved' : 'element-unwrapped', $summaryRows[0]['code']);

            $ast = $converter->convertToAstWithReport($html);
            $this->assertArrayNotHasKey('title', $ast->value['children'][0]);
            $this->assertSame($result->report(), $ast->report());
        }
    }

    public function testTheAstPreservesTheLinkDestinationAndTitle(): void
    {
        $html = '<details><summary><p><a href="url" title="tip">one</a></p></summary>b</details>';
        $converter = new HtmlToCarve();
        $this->assertSame("::: details\n[one](url \"tip\")\n\nb\n:::\n", $converter->convert($html));
        $result = $converter->convertToAstWithReport($html);
        $link = $result->value['children'][0]['children'][0]['children'][0];
        $this->assertSame('link', $link['type']);
        $this->assertSame('url', $link['href']);
        $this->assertSame('tip', $link['title']);
    }

    public function testRepresentableAndEmptySummariesStayUnreportedOnAReusedConverter(): void
    {
        $converter = new HtmlToCarve();
        $converter->convertWithReport('<details><summary><a href="url" title="tip">one</a></summary>b</details>');
        $result = $converter->convertWithReport('<details><summary><a href="url">one</a></summary>b</details>');
        $this->assertSame("::: details \"[one](url)\"\nb\n:::\n", $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
        $empty = $converter->convertWithReport('<details><summary></summary>b</details>');
        $this->assertSame("::: details\nb\n:::\n", $empty->value);
        $this->assertSame([], $empty->report()['diagnostics']);
    }
}
