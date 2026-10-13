<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use PHPUnit\Framework\TestCase;

class AnUnusedLinkDefinitionSurvivesEveryTextTargetTest extends TestCase
{
    public function testPlainTextBlanksAndReportsDeniedUnusedDefinition(): void
    {
        $source = "Text.\n\n[x]: /u\n\n[y]: javascript:alert(3)\n";
        $converter = CarveConverter::plainText();
        $result = $converter->convertWithReport($source);

        self::assertSame("Text.\n\n[x]: /u\n\n[y]:\n", $result->value);
        self::assertSame($converter->convert($source), $result->value);
        self::assertSame(1, $result->totalLosses);
        self::assertSame(['destination-denied' => 1], $result->lossCounts);
        self::assertSame('destination-denied', $result->losses[0]['code']);
        self::assertSame('plain', $result->losses[0]['target']);
        self::assertSame(5, $result->losses[0]['pos']['startLine']);
    }

    public function testUnusedUsedShadowedAndImageDefinitions(): void
    {
        foreach (['markdown', 'plainText', 'ansi'] as $target) {
            $converter = CarveConverter::$target();
            self::assertStringContainsString('[ref]: /u', $converter->convert("[Text][REF]\n\n[ref]: /u\n"));
            self::assertStringNotContainsString('[r]: /u', $converter->convert("[^n]\n\n[^n]: [t][r]\n\n[r]: /u\n"));
            self::assertStringNotContainsString('[r]: /u', $converter->convert("![t][r]\n\n[r]: /u\n"));
            $document = $converter->parse("[t][r]\n\n[r]: /new\n");
            $document->appendChild(new LinkReferenceDefinition('r', '/old'));
            $shadowed = $converter->render($document);
            self::assertStringContainsString('[r]: /old', $shadowed);
            self::assertStringNotContainsString('[r]: /new', $shadowed);
            $before = $converter->convert("[r]: /u \"title\"\n\ntext\n");
            self::assertStringContainsString('[r]: /u "title"', $before);
            self::assertStringNotContainsString('[r]: /u', $converter->convert("[t][r]\n\n[r]: /u \"title\"\n"));
            $document = $converter->parse("[t][r]\n\n[r]: /u \"new\"\n");
            $document->appendChild(new LinkReferenceDefinition('r', '/u', 'old'));
            self::assertStringContainsString('[r]: /u "old"', $converter->render($document));
            self::assertStringNotContainsString('[r]: /u', $converter->convert("[t][r]\n\n[r]: /u\n"));
        }
    }

    public function testAnsiDimsUnusedDefinition(): void
    {
        self::assertSame(
            "[Text][REF]\n\n\033[2m[ref]: /u\033[0m\n",
            CarveConverter::ansi()->convert("[Text][REF]\n\n[ref]: /u\n"),
        );
    }

    public function testReferenceInsideLinkTextDoesNotConsumeItsDefinition(): void
    {
        $source = "a [t[x][r2]][r] b\n\n[r]: /u\n\n[r2]: /v\n";
        foreach (['markdown', 'plainText', 'ansi'] as $target) {
            $output = CarveConverter::$target()->convert($source);
            self::assertStringContainsString('[r2]: /v', $output, $target);
            self::assertStringNotContainsString('[r]: /u', $output, $target);
        }
    }
}
