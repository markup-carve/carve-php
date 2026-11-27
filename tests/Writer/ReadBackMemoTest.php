<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6081: the steps after the C2-W-088 rounds take the read-backs and the tree print the rounds already made,
 * and give the form they gave before (C2-W-091). A table cell keeps the two decodings of a read-back apart.
 */
final class ReadBackMemoTest extends TestCase
{
    /**
     * @var string
     */
    private const PARAGRAPH = '^[/\\`[`[/[^]]][<] and [cfa \\[b0][k] text \\/x0 [c][]';

    /**
     * @var string
     */
    private const WRITTEN = '^\\[\\/\\`[\\`\\[\\/\\[^\\]\\]][<] and [cfa \\[b0][k] text \\/x0 [c][]';

    /**
     * @var string
     */
    private const DEFINITIONS = "[<]: /u\n\n[k]: /k\n\n[c]: /c\n";

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (['' => [], ' normalize' => ['normalize' => true]] as $name => $options) {
            $cases['bracket-heavy paragraphs' . $name] = [
                self::PARAGRAPH . "\n\n" . self::PARAGRAPH . "\n\n" . self::DEFINITIONS,
                self::WRITTEN . "\n\n" . self::WRITTEN . "\n\n" . self::DEFINITIONS,
                $options,
            ];
            $cases['table with an empty cell' . $name] = [
                "| a | |\n| ^[x] | [^] |\n\n" . self::PARAGRAPH . "\n\n" . self::DEFINITIONS,
                "| a | |\n| ^[x] | [^] |\n\n" . self::WRITTEN . "\n\n" . self::DEFINITIONS,
                $options,
            ];
        }

        return $cases;
    }

    /**
     * @param string $source
     * @param string $expected
     * @param array<string, mixed> $options
     */
    #[DataProvider('cases')]
    public function testFormKeptAfterTheRounds(string $source, string $expected, array $options): void
    {
        $written = Carve::formatWithDiagnostics($source, $options);
        $this->assertSame($expected, $written->source);
        $this->assertSame([], $written->diagnostics);
        $this->assertSame($expected, Carve::formatWithDiagnostics($written->source, $options)->source);
    }
}
