<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

final class LintIncludeValidationTest extends TestCase
{
    public function testInvalidIncludesStillFailWithoutAnIncludeOpener(): void
    {
        $this->expectException(OptionError::class);
        $this->expectExceptionMessage('option includes: not a resolver or an object with a resolver');

        Carve::lint('plain', ['includes' => 'bad']);
    }

    public function testValidIncludesNeedNoSecondParseWithoutAnOpener(): void
    {
        self::assertSame([], Carve::lint('plain', ['includes' => static fn (array $request): null => null]));
    }
}
