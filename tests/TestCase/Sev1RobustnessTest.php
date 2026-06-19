<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Severity-1 robustness: adversarial / programmatic input must not crash.
 */
class Sev1RobustnessTest extends TestCase
{
    public function testHugeFenceRunDoesNotThrowPcreQuantifierError(): void
    {
        // A fence run > 65535 chars used to be interpolated into a `{N,}`
        // quantifier, which PCRE rejects ("number too big in {} quantifier").
        $converter = new CarveConverter();
        $out = $converter->convert(str_repeat('`', 70000) . "\n");
        $this->assertIsString($out);

        // closers still match correctly at normal lengths
        $this->assertStringContainsString(
            '<pre><code class="language-php">',
            $converter->convert("````php\nx\n````\n"),
        );
    }

    public function testProgrammaticDigitKeyAttributeDoesNotThrowTypeError(): void
    {
        // PHP coerces an all-digit array key to int; renderAttributeArray must
        // cast it back to string before escape() instead of throwing TypeError.
        $doc = new Document();
        $p = new Paragraph();
        $p->setAttributes(['123' => 'v']);
        $doc->appendChild($p);
        $out = (new HtmlRenderer())->render($doc);
        $this->assertIsString($out);
    }
}
