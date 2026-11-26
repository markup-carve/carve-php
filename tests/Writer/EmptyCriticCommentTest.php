<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6096: `{##}` reads back as text (C2-I-063), so an empty critic comment is reported (C2-W-116), as by carve-js.
 */
final class EmptyCriticCommentTest extends TestCase
{
    public function testEmptyCriticCommentIsReported(): void
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $empty = ['type' => 'critic_comment', 'value' => ''];
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => [$text('a'), $empty, $text('b')]]]);

        $this->assertSame("a{##}b\n", $result->source);
        $this->assertSame(
            ['structure-unspellable /children/0/children/1'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }

    public function testEmptyCriticCommentInEmphasisIsReported(): void
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $emphasis = ['type' => 'emphasis', 'children' => [['type' => 'critic_comment', 'value' => '']]];
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => [$text('a'), $emphasis, $text('b')]]]);

        $this->assertSame("a{/{##}/}b\n", $result->source);
        $this->assertSame(
            ['structure-unspellable /children/0/children/1/children/0'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }
}
