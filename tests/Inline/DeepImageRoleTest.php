<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class DeepImageRoleTest extends TestCase
{
    public function testRoleFollowsCompletedInlineChildren(): void
    {
        $source = str_repeat('![', 201) . 'a' . str_repeat('](i)', 201);
        $json = Carve::toJson($source);
        $paragraph = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['children'][0];

        $this->assertArrayNotHasKey('role', $paragraph);
        $this->assertSame(['image', 'text'], array_column($paragraph['children'], 'type'));
        $this->assertSame('](i)', $paragraph['children'][1]['value']);
        $this->assertSame('paragraph', Carve::decodeTree($json)->children[0]->type);
        $this->assertStringStartsWith('<p><img ', Carve::toHtml($source));
        $this->assertStringEndsWith('](i)</p>', Carve::toHtml($source));
    }

    public function testWithinDepthLimitStillHasImageRole(): void
    {
        $source = str_repeat('![', 200) . 'a' . str_repeat('](i)', 200);
        $paragraph = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR)['children'][0];

        $this->assertSame('image', $paragraph['role']);
        $this->assertSame(['image'], array_column($paragraph['children'], 'type'));
    }
}
