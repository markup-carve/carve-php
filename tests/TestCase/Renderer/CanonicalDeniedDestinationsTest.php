<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalDeniedDestinationsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function destinations(): array
    {
        $cases = [];
        foreach (['javascript', 'JaVaScRiPt', 'vbscript', 'data', 'file', 'ms-msdt', 'vscode'] as $scheme) {
            foreach (['alert(1)', 'a(b(c))d', 'a\\)b', 'a\\(b', 'a\\\\b'] as $tail) {
                $cases[$scheme . ':' . $tail] = [$scheme, $tail];
            }
        }

        return $cases;
    }

    #[DataProvider('destinations')]
    public function testCanonicalSourcePreservesDestinations(string $scheme, string $tail): void
    {
        $source = '[x](' . $scheme . ':' . $tail . ') ![i](' . $scheme . ':' . $tail . ")\n";
        $writer = CarveConverter::carve();
        $written = $writer->convertWithReport($source, strictLosses: true);
        $before = $writer->parse($source)->getChildren()[0]->getChildren();
        $after = $writer->parse($written->value)->getChildren()[0]->getChildren();
        self::assertInstanceOf(Link::class, $before[0]);
        self::assertInstanceOf(Image::class, $before[2]);
        self::assertInstanceOf(Link::class, $after[0]);
        self::assertInstanceOf(Image::class, $after[2]);
        self::assertSame($before[0]->getDestination(), $after[0]->getDestination());
        self::assertSame($before[2]->getSource(), $after[2]->getSource());
        self::assertSame([], $written->losses);
        self::assertSame(0, $written->totalLosses);
        self::assertSame($written->value, $writer->convert($written->value));
        foreach ([CarveConverter::create(), CarveConverter::markdown()] as $renderer) {
            $result = $renderer->convertWithReport($written->value);
            self::assertSame(['destination-denied', 'destination-denied'], array_column($result->losses, 'code'));
        }
        $ansi = CarveConverter::ansi()->convertWithReport($written->value);
        self::assertSame(['destination-denied'], array_column($ansi->losses, 'code'));
    }

    public function testReferenceAndAutolinkDestinationsSurvive(): void
    {
        foreach (["[x][r]\n\n[r]: javascript:alert(1)\n", "<javascript:alert(1)>\n"] as $source) {
            $writer = CarveConverter::carve();
            $written = $writer->convertWithReport($source, strictLosses: true);
            $before = $writer->parse($source)->getChildren()[0]->getChildren()[0];
            $after = $writer->parse($written->value)->getChildren()[0]->getChildren()[0];
            self::assertInstanceOf(Link::class, $before);
            self::assertInstanceOf(Link::class, $after);
            self::assertSame('javascript:alert(1)', $before->getDestination());
            self::assertSame($before->getDestination(), $after->getDestination());
            self::assertSame(0, $written->totalLosses);
            self::assertSame($written->value, $writer->convert($written->value));
            foreach ([CarveConverter::create(), CarveConverter::markdown()] as $renderer) {
                self::assertSame(1, $renderer->convertWithReport($written->value)->totalLosses);
            }
        }
    }

    public function testLeadingControlsRemainAuthoredDestinationCharacters(): void
    {
        foreach (["\x01", "\x1F"] as $prefix) {
            foreach (['javascript', 'JaVaScRiPt', 'vbscript', 'data', 'file', 'ms-msdt', 'vscode', 'https'] as $scheme) {
                $destination = $prefix . $scheme . ':alert(1)';
                $source = '[x](' . $destination . ') ![i](' . $destination . ")\n";
                $writer = CarveConverter::carve();
                $before = $writer->parse($source)->getChildren()[0]->getChildren();
                self::assertInstanceOf(Link::class, $before[0]);
                self::assertSame($destination, $before[0]->getDestination());
                $written = $writer->convertWithReport($source, strictLosses: true);
                $after = $writer->parse($written->value)->getChildren()[0]->getChildren();
                self::assertInstanceOf(Link::class, $after[0]);
                self::assertInstanceOf(Image::class, $after[2]);
                self::assertSame($destination, $after[0]->getDestination());
                self::assertSame($destination, $after[2]->getSource());
                self::assertSame($source, $written->value);
                self::assertSame(0, $written->totalLosses);
                self::assertSame($written->value, $writer->convert($written->value));
                foreach ([CarveConverter::create(), CarveConverter::markdown()] as $renderer) {
                    $result = $renderer->convertWithReport($written->value);
                    self::assertSame($scheme === 'https' ? 0 : 2, $result->totalLosses);
                    self::assertSame($renderer->convert($source), $result->value);
                }
            }
        }
    }

    public function testBalancedDeniedParenthesesStayReadable(): void
    {
        $source = "[x](javascript:alert(1))\n";
        self::assertSame($source, CarveConverter::carve()->convert($source));
    }
}
