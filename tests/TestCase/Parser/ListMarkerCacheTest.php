<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ListMarkerCacheTest extends TestCase
{
    public function testRepeatedMarkersFollowBulletSettingsAndDoNotShareResults(): void
    {
        $parser = new ListParser();
        self::assertNull($parser->parseListItemMarker('+ item'));
        $parser->allowPlusBullet();
        self::assertNotNull($parser->parseListItemMarker('+ item'));
        $clone = clone $parser;
        $parser->allowPlusBullet(false);
        self::assertNull($parser->parseListItemMarker('+ item'));
        self::assertNotNull($clone->parseListItemMarker('+ item'));
        $first = $parser->parseListItemMarker('- item');
        self::assertNotNull($first);
        $first['content'] = 'changed';
        self::assertSame('item', $parser->parseListItemMarker('- item')['content']);
        for ($i = 0; $i < 256; $i++) {
            self::assertSame('item ' . $i, $parser->parseListItemMarker('- item ' . $i)['content']);
        }
        self::assertSame('item', $parser->parseListItemMarker('- item')['content']);
    }

    public function testLongLinesAreNotRetained(): void
    {
        $parser = new ListParser();
        $content = str_repeat('x', 4096);
        self::assertSame($content, $parser->parseListItemMarker('- ' . $content)['content']);
        self::assertNull($parser->parseListItemMarker($content));
        self::assertSame([], (new ReflectionProperty($parser, 'parsedMarkerCache'))->getValue($parser));
    }

    public function testMarkerSubclassesCanChangeTheirPatternsBetweenCalls(): void
    {
        $parser = new class extends ListParser {
            public function setBullets(string $bullets): void
            {
                $this->bulletMarkerClass = $bullets;
                $this->capturePatterns = null;
                $this->markerTokens = null;
                $this->markerHeads = null;
            }
        };
        self::assertNull($parser->parseListItemMarker('+ item'));
        $parser->setBullets('-*+');
        self::assertNotNull($parser->parseListItemMarker('+ item'));
        $parser->setBullets('-*');
        self::assertNull($parser->parseListItemMarker('+ item'));
    }
}
