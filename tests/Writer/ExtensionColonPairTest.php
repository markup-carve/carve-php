<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6551 (carve-php), C2-I-094: the colon of an inline extension takes the backslash only where its bracket pairs.
 */
final class ExtensionColonPairTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'unclosed extension below an escaped comment' => ["\\%%\n:a[\n", "\\%%\n:a[\n"],
            'unclosed extension below an escaped heading' => ["\\# a\n:a[\n", "\\# a\n:a[\n"],
            'unclosed extension inside the line' => ["\\%%\nx :a[b\n", "\\%%\nx :a[b\n"],
            'closed extension text keeps its colon escape' => ["\\%%\n\\:a[b]\n", "\\%%\n\\:a[b]\n"],
            'nested pair leaves the outer bracket open' => ["\\%%\n:a[x[y]\n", "\\%%\n:a[x[y]\n"],
            'bracket inside a code span closes nothing' => ["\\%%\n:a[`]`\n", "\\%%\n:a[`]`\n"],
            'attributed node holds no closer' => ["\\%%\n:a[foo *bar*{#x}\n", "\\%%\n:a[foo *bar*{#x}\n"],
            'closer in an attribute value' => ["\\%%\n:a[foo *bar*{title=\"]\"}\n", "\\%%\n:a[foo *bar*{title=]}\n"],
            'closer inside an attributed node' => ["\\%%\n\\:a[foo *b]r*{#x}\n", "\\%%\n\\:a[foo *b]r*{#x}\n"],
            'bracket behind underscores still closes' => ["\\%%\n\\:a[_x_]\n", "\\%%\n\\:a[\\_x_]\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheFormReadsBack(string $source, string $expected): void
    {
        $result = Writer::formatWithDiagnostics($source);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], $result->codes());
        $this->assertSame(self::tree($source), self::tree($result->source));
        $this->assertSame($expected, Carve::format($expected));
    }

    public function testARunOfUnclosedExtensionsScalesLinearly(): void
    {
        $small = "\\%%\n" . str_repeat(':a[', 2000) . "\n";
        $large = "\\%%\n" . str_repeat(':a[', 16000) . "\n";
        $excess = Linearity::excess(
            static fn () => Writer::formatWithDiagnostics($small),
            static fn () => Writer::formatWithDiagnostics($large),
            8,
            2,
        );
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return self::strip($tree);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function strip(array $node): array
    {
        unset($node['pos'], $node['srcByteLength']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::strip($value);
            }
        }

        return $node;
    }
}
