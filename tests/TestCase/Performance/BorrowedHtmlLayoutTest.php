<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Performance;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Performance\BorrowedHtmlLayout;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class BorrowedHtmlLayoutTest extends TestCase
{
    private const ROUTING_SOURCE = <<<'CRV'
# Benchmark

[site]: https://example.com "Example"

Paragraph has *strong*, /emphasis/, `code`, and a [link][site].

- first
- second
  - nested

> quoted /text/

```js
return 1;
```

| Name | Value |
| --- | ---: |
| alpha | 1 |
CRV;

    public function testTheBenchmarkShapedCoreRouteHasTypedAcceptanceCountsAndExactHtml(): void
    {
        $attempt = (new BorrowedHtmlLayout())->render(self::ROUTING_SOURCE, true);

        $this->assertNotNull($attempt);
        $this->assertSame([
            'headings' => 1,
            'paragraphs' => 1,
            'blockQuotes' => 1,
            'codeFences' => 1,
            'thematicBreaks' => 0,
            'unorderedListItems' => 3,
            'orderedListItems' => 0,
            'tableRows' => 2,
            'linkDefinitions' => 1,
            'consumedLines' => 13,
            'activeDefinitions' => 1,
        ], $attempt['accepted']);
        $this->assertSame($this->authoritative()->convert(self::ROUTING_SOURCE), $attempt['html']);
    }

    public function testEveryAcceptedPinnedCorpusSourceHasExactShadowParity(): void
    {
        $layout = new BorrowedHtmlLayout();
        $converter = $this->authoritative();
        $accepted = 0;
        $paths = glob(__DIR__ . '/../../spec/tests/corpus/*.crv');
        $this->assertIsArray($paths);
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $attempt = $layout->render($source, true);
            if ($attempt === null) {
                continue;
            }
            $accepted++;
            $this->assertSame($converter->convert($source), $attempt['html'], basename($path));
        }

        $this->assertSame(47, $accepted, 'A fast-path routing change needs explicit review.');
    }

    public function testAmbiguousOrStatefulDocumentsFallBackBeforePublishingOutput(): void
    {
        $layout = new BorrowedHtmlLayout();
        foreach (
            [
                "---\ntitle: x\n---\n# x\n",
                "::: note\nx\n:::\n",
                "[^n]: note\n\nref[^n]\n",
                "- loose\n\n- list\n",
                "non-ASCII café\n",
            ] as $source
        ) {
            $this->assertNull($layout->render($source), $source);
        }
    }

    private function authoritative(): CarveConverter
    {
        // A caller-supplied renderer deliberately disables the facade.
        return new CarveConverter(renderer: new HtmlRenderer());
    }
}
