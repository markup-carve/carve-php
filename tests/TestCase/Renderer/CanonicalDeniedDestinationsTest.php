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
        foreach (["\x01", "\x1F", "\x7F"] as $prefix) {
            foreach (['javascript', 'JaVaScRiPt', 'vbscript', 'data', 'file', 'ms-msdt', 'vscode', 'https'] as $scheme) {
                $destination = $prefix . $scheme . ':alert(1)';
                $source = '[x](' . $destination . ') ![i](' . $destination . ")\n";
                $writer = CarveConverter::carve();
                $before = $writer->parse($source)->getChildren()[0]->getChildren();
                self::assertInstanceOf(Link::class, $before[0]);
                self::assertSame($destination, $before[0]->getDestination());
                self::assertInstanceOf(Image::class, $before[2]);
                self::assertSame($destination, $before[2]->getSource());
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

    public function testParsedPrefixBoundaries(): void
    {
        $writer = CarveConverter::carve();
        foreach (["\x0B", "\x0C", "\u{00A0}", "\u{3000}"] as $prefix) {
            $source = '[x](' . $prefix . "javascript:alert(1))\n";
            $node = $writer->parse($source)->getChildren()[0]->getChildren()[0];
            self::assertNotInstanceOf(Link::class, $node);
            self::assertSame($source, $writer->convert($source));
        }
        $source = "[x](\x00javascript:alert(1))\n";
        $node = $writer->parse($source)->getChildren()[0]->getChildren()[0];
        self::assertInstanceOf(Link::class, $node);
        self::assertSame("\u{FFFD}javascript:alert(1)", $node->getDestination());
        self::assertSame("[x](\u{FFFD}javascript:alert(1))\n", $writer->convert($source));
    }

    public function testWhitespacePrefixHandlingStaysCompatible(): void
    {
        foreach (["\t", "\n", "\r", ' ', "\u{00A0}"] as $prefix) {
            $writer = CarveConverter::carve();
            $document = $writer->parse("[x](https://example.com)\n");
            $link = $document->getChildren()[0]->getChildren()[0];
            self::assertInstanceOf(Link::class, $link);
            $link->setDestination($prefix . 'https://example.com');
            $written = $writer->renderWithReport($document, strictLosses: true);
            self::assertSame("[x](https://example.com)\n", $written->value);
            self::assertSame($written->value, $writer->convert($written->value));
            self::assertInstanceOf(Link::class, $writer->parse($written->value)->getChildren()[0]->getChildren()[0]);
        }
    }

    public function testBalancedDeniedParenthesesStayReadable(): void
    {
        $source = "[x](javascript:alert(1))\n";
        self::assertSame($source, CarveConverter::carve()->convert($source));
    }
}
