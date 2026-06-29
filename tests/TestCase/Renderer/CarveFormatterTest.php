<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function file_get_contents;
use function glob;
use function preg_replace;

#[Group('corpus')]
class CarveFormatterTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, crv: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/spec/tests/corpus';
        $crvFiles = glob($dir . '/*.crv') ?: [];
        if ($crvFiles === []) {
            throw new RuntimeException('Carve spec corpus not found at ' . $dir);
        }

        $cases = [];
        foreach ($crvFiles as $crvPath) {
            $slug = basename($crvPath, '.crv');
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
            ];
        }

        return $cases;
    }

    #[DataProvider('corpusProvider')]
    public function testCorpusFormatsSemanticallyAndIdempotently(string $slug, string $crv): void
    {
        $formatted = CarveConverter::toCarve($crv);
        $reformatted = CarveConverter::toCarve($formatted);

        $converter = new CarveConverter();
        $this->assertSame(
            $this->normalizeHtml($converter->convert($crv)),
            $this->normalizeHtml($converter->convert($formatted)),
            'Formatted output changed rendered HTML for ' . $slug,
        );
        $this->assertSame($formatted, $reformatted, 'Formatter is not idempotent for ' . $slug);

        $converter->parse($formatted);
        $this->addToAssertionCount(1);
    }

    public function testTargetedFormattingRules(): void
    {
        $nbsp = "\u{00A0}";
        $cases = [
            "a\n\n\nb\n" => "a\n\nb\n",
            "+ item\n" => "\\+ item\n",
            "```\na ``` b\n```\n" => "````\na ``` b\n````\n",
            "{k=v .cls #id}\n# H\n" => "{k=v .cls #id}\n# H\n",
            "a  \n{$nbsp}\t \n" => "a\n{$nbsp}\n",
            "{.line-block}\n:::\na\nb\n:::\n" => "{.line-block}\n:::\na\nb\n:::\n",
            "a /em/ *strong* _u_ ~s~ ^sup^ ,sub, =mark= `code`\n" =>
                "a /em/ *strong* _u_ ~s~ ^sup^ ,sub, =mark= `code`\n",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, CarveConverter::toCarve($input));
        }
    }

    protected function normalizeHtml(string $html): string
    {
        $html = (string)preg_replace('/[ \t]+$/m', '', $html);

        return rtrim($html, "\n");
    }
}
