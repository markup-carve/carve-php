<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Context;
use MarkupCarve\Carve\Writer\Text;
use PHPUnit\Framework\TestCase;

final class PlainTextFastPathTest extends TestCase
{
    public function testSafeLineStartTextClearsStaleEscapeOffsetsWithoutPerByteWork(): void
    {
        $context = new Context();
        $context->lineStart = true;
        $context->escapedAt = [0 => true];
        $plain = str_repeat('alpha beta ', 8192);

        self::assertSame($plain, Text::escape($plain, $context, 10));
        self::assertSame([], $context->escapedAt);
    }
}
