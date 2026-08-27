<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use PHPUnit\Framework\TestCase;

class ExternalLinksExtensionTest extends TestCase
{
    public function testExternalLinkGetsTargetAndRel(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testInternalLinkUnchanged(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Home](/home)');

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testInternalHostsExcluded(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension(
            internalHosts: ['example.com', 'www.example.com'],
        ));

        $html = $converter->convert('[Example](https://example.com/page)');

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testCustomTargetAndRel(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension(
            target: '_self',
            rel: 'external',
        ));

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('target="_self"', $html);
        $this->assertStringContainsString('rel="external"', $html);
    }

    public function testEmptyTargetIsOmittedWhileRelIsRetained(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension(
            internalHosts: ['example.org'],
            target: '',
        ));

        $html = $converter->convert('[a](https://elsewhere.test/x)');

        $this->assertSame(
            "<p><a href=\"https://elsewhere.test/x\" rel=\"noopener noreferrer\">a</a></p>\n",
            $html,
        );
    }

    public function testNofollow(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension(
            nofollow: true,
        ));

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('nofollow', $html);
    }

    public function testHttpAndHttpsLinks(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $httpHtml = $converter->convert('[HTTP](http://example.com)');
        $httpsHtml = $converter->convert('[HTTPS](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $httpHtml);
        $this->assertStringContainsString('target="_blank"', $httpsHtml);
    }

    public function testMailtoLinkUnchanged(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Email](mailto:test@example.com)');

        $this->assertStringNotContainsString('target=', $html);
    }
}
