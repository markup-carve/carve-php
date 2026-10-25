<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotAttributeRecoveryTest extends TestCase
{
    public function testDottedHeadingAttributesAreRecovered(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("{#ch1.1}\n# Head\n");
        self::assertSame("{#ch1 .1}\n# Head\n", $result->value);
    }

    public function testLeadingDigitsStayOnBlocksAndInlineSpans(): void
    {
        $converter = new DjotToCarve();
        self::assertSame("{#1start .2part}\n# Head\n", $converter->convert("{#1start .2part}\n# Head\n"));
        self::assertSame("[Word]{#1start .2part}\n", $converter->convert("Word{#1start .2part}\n"));
    }

    public function testRejectedPartsHaveNamedDiagnostics(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("{.good #bad!id .bad?class}\n# Head\n");
        self::assertSame("{.good}\n# Head\n", $result->value);
        $messages = [];
        foreach ($result->diagnostics as $diagnostic) {
            if ($diagnostic->code === 'djot-attribute-dropped') {
                $messages[] = $diagnostic->message;
            }
        }
        self::assertSame([
            'Djot attribute id could not be represented in Carve and was dropped.',
            'Djot attribute class could not be represented in Carve and was dropped.',
        ], $messages);
    }

    public function testInlineSpanSurvivesRejectedClass(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("Word{#part.2 .bad?class}\n");
        self::assertSame("[Word]{#part .2}\n", $result->value);
        self::assertContains('djot-attribute-dropped', array_map(static fn ($row): string => $row->code, $result->diagnostics));
    }

    public function testInvalidKeyValueAndLiteralDelimitersStayLiteral(): void
    {
        $converter = new DjotToCarve();
        self::assertSame('x\\{.good bad=!?}', $converter->convert('x{.good bad=!?}'));
        self::assertStringContainsString('`{#a.b}`', $converter->convert('`{#a.b}`'));
    }
}
