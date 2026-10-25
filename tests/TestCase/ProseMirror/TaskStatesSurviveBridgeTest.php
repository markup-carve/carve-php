<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskStatesSurviveBridgeTest extends TestCase
{
    #[DataProvider('states')]
    public function testAStateSurvives(string $state): void
    {
        $source = "- [$state] item\n";
        $document = (new CarveConverter())->parse($source);
        $payload = (new ProseMirrorRenderer())->render($document);
        $returned = (new ProseMirrorToCarve())->convert($payload);

        $this->assertStringContainsString("[$state]", CarveConverter::carve()->render($returned));
    }

    /** @return array<string, array{0: string}> */
    public static function states(): array
    {
        return ['progress' => ['/'], 'blocked' => ['!']];
    }
}
