<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class AnEmptyHtmlCodeLabelRemainsAnExplicitLabelTest extends TestCase
{
    public function testAnEmptyPresentLabelIsDifferentFromAnAbsentOrInvalidLabel(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['' => true, 'label' => true, ']' => false, "a\nb" => false] as $label => $valid) {
                $html = '<pre data-carve-label="' . $label . '"><code class="language-php">echo 1;' . "\n</code></pre>";
                $tree = (new HtmlToCarve(importMode: $mode))->convertToAst($html);
                self::assertSame($valid, array_key_exists('label', $tree['children'][0]));
                if ($valid) {
                    self::assertSame($label, $tree['children'][0]['label']);
                }
            }
            $tree = (new HtmlToCarve(importMode: $mode))->convertToAst('<pre><code>x</code></pre>');
            self::assertArrayNotHasKey('label', $tree['children'][0]);
            $html = (new CarveConverter(roundTripMode: true))->convert("```php []\necho 1;\n```\n");
            $back = (new HtmlToCarve(importMode: $mode))->convert($html);
            self::assertStringContainsString('php []', $back);
        }
    }
}
