<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\RenderLossAwareRendererInterface;
use MarkupCarve\Carve\Renderer\RenderLossCollectorTrait;
use MarkupCarve\Carve\Renderer\RenderMode;
use MarkupCarve\Carve\Renderer\RenderModeRendererInterface;
use MarkupCarve\Carve\Renderer\RenderTargetInterface;
use MarkupCarve\Carve\Renderer\SafeModeRendererInterface;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use MarkupCarve\Carve\Renderer\SmartTypographyRendererInterface;
use MarkupCarve\Carve\Renderer\StaticRenderExtensionsInterface;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\TestCase;

class RendererCapabilitiesTest extends TestCase
{
    public function testCustomTargetReportsLossesAndEnforcesStrictRendering(): void
    {
        $renderer = new class implements RendererInterface, RenderTargetInterface, RenderLossAwareRendererInterface {
            use RenderLossCollectorTrait;

            public function getRenderTarget(): string
            {
                return 'custom';
            }

            public function render(Document $document): string
            {
                $this->recordRawFormatDropped($document, 'latex', 'block');

                return 'custom output';
            }
        };
        $converter = new CarveConverter(renderer: $renderer);
        $report = $converter->convertWithReport('text');
        self::assertSame('custom output', $report->value);
        self::assertSame('custom', $report->losses[0]['target']);
        self::assertSame(1, $report->totalLosses);
        self::assertSame(1, $converter->convertWithReport('text', maxRenderLosses: 0)->totalLosses);
        self::assertTrue($converter->convertWithReport('text', maxRenderLosses: 0)->truncated);

        $this->expectException(RenderLossException::class);
        $converter->convertWithReport('text', strictLosses: true);
    }

    public function testCapabilitiesConfigureAComposedHtmlRenderer(): void
    {
        $renderer = new class implements RendererInterface, RenderTargetInterface, SafeModeRendererInterface, RenderModeRendererInterface, SmartTypographyRendererInterface, StaticRenderExtensionsInterface {
            private HtmlRenderer $html;

            public function __construct()
            {
                $this->html = new HtmlRenderer();
            }

            public function addStaticRenderExtension(StaticRenderExtensionInterface $extension): self
            {
                $this->html->addStaticRenderExtension($extension);

                return $this;
            }

            public function getRenderTarget(): string
            {
                return 'html';
            }

            public function render(Document $document): string
            {
                return $this->html->render($document);
            }

            public function setSafeMode(?SafeMode $safeMode): self
            {
                $this->html->setSafeMode($safeMode);

                return $this;
            }

            public function getSafeMode(): ?SafeMode
            {
                return $this->html->getSafeMode();
            }

            public function setRenderMode(string $mode): self
            {
                $this->html->setRenderMode($mode);

                return $this;
            }

            public function getRenderMode(): string
            {
                return $this->html->getRenderMode();
            }

            public function setSmartTypography(SmartTypographyMode $mode): self
            {
                $this->html->setSmartTypography($mode);

                return $this;
            }

            public function getSmartTypography(): SmartTypographyMode
            {
                return $this->html->getSmartTypography();
            }
        };
        $converter = new CarveConverter(renderer: $renderer, smartTypography: false);
        $safeMode = new SafeMode();
        $converter->setSafeMode($safeMode)->setRenderMode(RenderMode::STATIC);
        $context = BeforeRenderContext::forRenderer($renderer);

        self::assertSame($safeMode, $context->safeMode());
        self::assertTrue($context->targetIsHtml());
        self::assertTrue($context->isStatic());
        self::assertSame(RenderMode::STATIC, $converter->getRenderMode());
        self::assertSame(SmartTypographyMode::Source, $context->smartTypography());
        self::assertStringContainsString('--', $converter->convert('one -- two'));
        $converter->addExtension(new class implements StaticRenderExtensionInterface {
            public function register(CarveConverter $converter): void
            {
            }

            public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
            {
                if (!$event->getNode() instanceof Paragraph) {
                    return false;
                }
                $event->setHtml('<p>static extension</p>');

                return true;
            }
        });
        self::assertSame('<p>static extension</p>', trim($converter->convert('text')));
    }

    public function testLegacyTypographyMethodsStillWork(): void
    {
        $renderer = new class implements RendererInterface {
            private SmartTypographyMode $mode = SmartTypographyMode::Glyph;

            public function setSmartTypography(SmartTypographyMode $mode): self
            {
                $this->mode = $mode;

                return $this;
            }

            public function getSmartTypography(): SmartTypographyMode
            {
                return $this->mode;
            }

            public function render(Document $document): string
            {
                return $this->mode->value;
            }
        };
        $converter = new CarveConverter(renderer: $renderer, smartTypography: false);

        self::assertSame(SmartTypographyMode::Source->value, $converter->convert('text'));
        self::assertSame(SmartTypographyMode::Source, BeforeRenderContext::forRenderer($renderer)->smartTypography());
        self::assertFalse(BeforeRenderContext::forRenderer($renderer)->targetIsHtml());
    }
}
