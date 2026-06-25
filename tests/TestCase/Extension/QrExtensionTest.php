<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\QrExtension;
use PHPUnit\Framework\TestCase;

class QrExtensionTest extends TestCase
{
    /**
     * Render with a fake encoder that records the payload it is handed verbatim
     * (HTML-escaped into a data attribute) so tests can assert the exact QR
     * payload string the builder produced.
     */
    protected function render(string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new QrExtension(
            static fn (string $payload): string =>
                '<svg data-payload="' . htmlspecialchars($payload, ENT_QUOTES) . '"></svg>',
        ));

        return trim($converter->convert($djot));
    }

    /**
     * Pull the recorded payload out of the rendered <figure>.
     */
    protected function payload(string $djot): string
    {
        $html = $this->render($djot);
        self::assertMatchesRegularExpression('/<figure class="qr"><svg data-payload="[^"]*"><\/svg><\/figure>/', $html);
        preg_match('/data-payload="([^"]*)"/', $html, $m);

        return htmlspecialchars_decode($m[1], ENT_QUOTES);
    }

    public function testBareQrIsAUrlPayload(): void
    {
        self::assertSame('https://example.com', $this->payload("```qr\nhttps://example.com\n```"));
    }

    public function testTel(): void
    {
        self::assertSame('tel:+15550100', $this->payload("```qr-tel\n+15550100\n```"));
    }

    public function testSms(): void
    {
        self::assertSame('SMSTO:+15550100:Hi there', $this->payload("```qr-sms\nto: +15550100\nbody: Hi there\n```"));
    }

    public function testEmail(): void
    {
        self::assertSame(
            'mailto:a@b.com?subject=Hello&body=Hi%20there',
            $this->payload("```qr-email\nto: a@b.com\nsubject: Hello\nbody: Hi there\n```"),
        );
    }

    public function testGeo(): void
    {
        self::assertSame('geo:37.42,-122.08', $this->payload("```qr-geo\n37.42,-122.08\n```"));
    }

    public function testWifiEscapesStructuralChars(): void
    {
        // Code-block content is verbatim; a `;` in a value is structural and
        // must be backslash-escaped so it cannot terminate the WIFI field.
        self::assertSame(
            'WIFI:T:WPA;S:Home\\;Net;P:pa\\;ss;H:false;;',
            $this->payload("```qr-wifi\nssid: Home;Net\npassword: pa;ss\nsecurity: WPA\n```"),
        );
    }

    public function testMecard(): void
    {
        self::assertSame(
            'MECARD:N:Doe,Jane;TEL:+1;EMAIL:jane@acme.com;;',
            $this->payload("```qr-mecard\nname: Jane Doe\ntel: +1\nemail: jane@acme.com\n```"),
        );
    }

    public function testVcard(): void
    {
        self::assertSame(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Doe;Jane;;;\r\nFN:Jane Doe\r\nORG:ACME\r\nEMAIL:jane@acme.com\r\nEND:VCARD",
            $this->payload("```qr-vcard\nname: Jane Doe\norg: ACME\nemail: jane@acme.com\n```"),
        );
    }

    public function testVcardEscapesValuesSoAuthorWritesPlainText(): void
    {
        // Author types a plain comma; the builder escapes it for vCard.
        self::assertSame(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Doe;Jane;;;\r\nFN:Jane Doe\r\nORG:ACME\\, Inc\r\nEND:VCARD",
            $this->payload("```qr-vcard\nname: Jane Doe\norg: ACME, Inc\n```"),
        );
    }

    public function testNonQrCodeBlockIsUntouched(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new QrExtension(static fn (string $p): string => '<svg></svg>'));
        $html = $converter->convert("```php\necho 1;\n```");

        self::assertStringContainsString('<code class="language-php">', $html);
        self::assertStringNotContainsString('<figure class="qr">', $html);
    }

    public function testTypeForMapping(): void
    {
        self::assertSame('url', QrExtension::typeFor('qr'));
        self::assertSame('wifi', QrExtension::typeFor('qr-wifi'));
        self::assertNull(QrExtension::typeFor('php'));
        self::assertNull(QrExtension::typeFor(null));
    }
}
