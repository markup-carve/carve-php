<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ReferenceDefinitionStaysOnOneLineTest extends TestCase
{
    public function testEmptyDestinationCannotClaimTheNextLazyItemLine(): void
    {
        $converter = new CarveConverter();

        $this->assertSame(
            "<ul>\n  <li>[d]:\n:::</li>\n</ul>",
            trim($converter->convert("* [d]: \n :::\n")),
        );
    }

    public function testOrdinaryLazyTextAfterTheSamePrefixIsPreserved(): void
    {
        $converter = new CarveConverter();

        $this->assertSame(
            "<ul>\n  <li>[d]:\ntext</li>\n</ul>",
            trim($converter->convert("* [d]: \n text\n")),
        );
    }

    public function testAValidSingleLineDefinitionStillRegisters(): void
    {
        $converter = new CarveConverter();

        $this->assertSame(
            "<ul>\n  <li></li>\n</ul>\n<p>:::</p>",
            trim($converter->convert("* [d]: /u\n :::\n")),
        );
    }
}
