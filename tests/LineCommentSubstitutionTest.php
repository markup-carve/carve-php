<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

final class LineCommentSubstitutionTest extends TestCase
{
    public function testACommentedArrowCannotSplitASubstitution(): void
    {
        $source = '{~a %% b~>c~} d';

        self::assertSame('<p>{~a d</p>', (new CarveConverter())->convert($source));
    }

    public function testCommentNodesInEitherSubstitutionHalfCrossTheBridge(): void
    {
        foreach (['{~a~>b %% c~} d', "{~a %% b~>c\n~>d~}"] as $source) {
            $document = (new CarveConverter())->parse($source);
            $wire = (new ProseMirrorRenderer())->render($document);
            $back = (new ProseMirrorToCarve())->convert($wire);

            self::assertSame($source . "\n", CarveConverter::carve()->render($back));
        }
    }
}
