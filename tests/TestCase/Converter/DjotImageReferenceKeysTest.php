<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotImageReferenceKeysTest extends TestCase
{
    /**
     * @return list<array{alt: string, src: string}>
     */
    private function images(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $rows = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $rows[] = ['alt' => $image->getAttribute('alt'), 'src' => $image->getAttribute('src')];
        }

        return $rows;
    }

    public function testNativeImageReferencesResolve(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-image-reference-keys.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $result = (new DjotToCarve())->convertWithFidelityReport($row['source']);
            $expected = $this->images($row['html']);
            $blank = false;
            foreach ($expected as &$image) {
                $blank = $blank || preg_match('/\n[ \t]*\n/', $image['alt']) === 1;
                $image['alt'] = preg_replace('/\n(?:[ \t]*\n)+/', "\n", $image['alt']);
            }
            unset($image);
            self::assertSame($expected, $this->images((new CarveConverter())->convert($result->value)), $row['source']);
            self::assertEmpty(array_filter($result->report()['diagnostics'], static fn (array $d): bool => str_contains($d['message'], 'unresolved Djot image reference') || str_contains($d['message'], 'with an empty destination')));
            if ($blank) {
                self::assertNotEmpty(array_filter($result->report()['diagnostics'], static fn (array $d): bool => str_contains($d['message'], 'image alternative text with a blank line')));
            }
        }
    }

    public function testUnresolvedImagesRemainUnresolved(): void
    {
        $result = (new DjotToCarve())->convert("![a [b](u)\nc][]\n\n[other]: img");
        self::assertSame([], $this->images((new CarveConverter())->convert($result)));
    }

    public function testExplicitReferenceNamesRemainAuthored(): void
    {
        $result = (new DjotToCarve())->convert("![a [b](u)\nc][named]\n\n[named]: img");
        self::assertSame([['alt' => "a b\nc", 'src' => 'img']], $this->images((new CarveConverter())->convert($result)));
    }

    public function testFootnoteLabelsStayOutsideImageResolution(): void
    {
        $result = (new DjotToCarve())->convert("![^a][]\n\n[^a]: note");
        self::assertStringContainsString('[]', $result);
        self::assertSame([], $this->images((new CarveConverter())->convert($result)));
    }

    public function testOuterImageEmptyDestinationIsReported(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport('![a ![b]() c]()');
        self::assertNotEmpty(array_filter($result->report()['diagnostics'], static fn (array $d): bool => str_contains($d['message'], 'An image with an empty destination')));
    }
}
