<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;

final class LossFieldPathTest extends TestCase
{
    public function testSuppliedFallbackLinkNamesHrefField(): void
    {
        foreach (Corpus::clauseCases() as $case) {
            if (($case['id'] ?? null) !== 'CC-459') {
                continue;
            }
            $tree = Carve::decodeTree(json_encode($case['tree'], JSON_THROW_ON_ERROR));
            $result = Carve::renderWithDiagnostics($tree);
            self::assertSame('<p><a href="">a</a></p>', $result->html);
            self::assertSame('/children/0/fallback/0/children/0/href', $result->diagnostics[1]->path);

            return;
        }
        self::fail('CC-459 is not pinned');
    }

    public function testSuppliedImageNamesSrcField(): void
    {
        $tree = Carve::decodeTree(Carve::encodeTree(Carve::parse('![a](javascript:x)')));
        $result = Carve::renderWithDiagnostics($tree);
        self::assertSame('/children/0/children/0/src', $result->diagnostics[0]->path);
        self::assertSame('Blanked a denied image source', $result->diagnostics[0]->message);
    }

    public function testSourceLossStillNamesOwningNode(): void
    {
        $result = Carve::renderWithDiagnostics('[a](javascript:x)');
        self::assertSame('/children/0/children/0', $result->diagnostics[0]->path);
    }
}
