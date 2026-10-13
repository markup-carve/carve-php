<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use PHPUnit\Framework\TestCase;

class AnUnusedLinkDefinitionSurvivesEveryTextTargetTest extends TestCase
{
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
