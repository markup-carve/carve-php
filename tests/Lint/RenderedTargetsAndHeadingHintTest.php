<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-088, C2-H-089, C2-I-084: rendered footnote ids are fragment targets; a reference hint names heading text.
 */
final class RenderedTargetsAndHeadingHintTest extends TestCase
{
    public function testInlineFootnoteIdsAreTargets(): void
    {
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("a ^[n] b\n\n[x](#fn1) [y](#fnref1) [z](#fn2)\n"));

        self::assertSame(['broken-fragment-link'], $codes);
    }

    public function testHeadingTextHintIsUnquoted(): void
    {
        $messages = array_map(static fn ($d): string => $d->message, Carve::lint("# Big  Title\n\n[x][big title]\n"));

        self::assertCount(1, $messages);
        self::assertStringContainsString('; the label Big Title differs only in case,', $messages[0]);
    }

    public function testMetadataKeyIsNoFragmentTarget(): void
    {
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("a\n\n{#m}\n---yaml\nk: v\n---\n\n[x](#m)\n"));

        self::assertSame(['broken-fragment-link'], $codes);
    }
}
