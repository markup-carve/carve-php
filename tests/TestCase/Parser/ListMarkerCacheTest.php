<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ListMarkerCacheTest extends TestCase
{
    public function testAttributePayloadCacheIncludesItsByteBoundary(): void
    {
        $parser = new ListParser();
        $cache = new ReflectionProperty($parser, 'markerAttributeCache');
        foreach ([2048 => true, 2049 => false] as $bytes => $retained) {
            $payload = '#' . str_repeat('x', $bytes - 1);
            self::assertNotNull($parser->parseListItemMarker('-{' . $payload . '} item'));
            self::assertSame($retained, array_key_exists($payload, $cache->getValue()));
        }
    }

    public function testLargeAttributePayloadsAreNotRetained(): void
    {
        $parser = new ListParser();
        $cache = new ReflectionProperty($parser, 'markerAttributeCache');
        $before = $cache->getValue();
        $id = 'long-' . str_repeat('x', 4096);
        self::assertSame($id, $parser->parseListItemMarker('-{#' . $id . '} item')['attributes']['id']);
        self::assertSame($before, $cache->getValue());
    }

    public function testAttributeValidationOverridesOwnTheirCachedResults(): void
    {
        $payload = '#marker-cache-override';
        (new ListParser())->parseListItemMarker('-{' . $payload . '} item');
        $parser = new class ('first') extends ListParser {
            public int $validations = 0;

            public function __construct(private string $id)
            {
            }

            public function setId(string $id): void
            {
                $this->id = $id;
                $this->instanceMarkerAttributeCache = [];
            }

            public function attributes(string $body): ?array
            {
                return $this->markerAttributes($body);
            }

            protected function validateMarkerAttributes(string $body): ?array
            {
                $this->validations++;

                return ['id' => $this->id];
            }
        };
        $class = $parser::class;
        $other = new $class('second');
        self::assertSame(['id' => 'first'], $parser->attributes($payload));
        self::assertSame(['id' => 'second'], $other->attributes($payload));
        self::assertSame(['id' => 'first'], $parser->attributes($payload));
        self::assertSame(1, $parser->validations);
        self::assertSame(1, $other->validations);
        $clone = clone $parser;
        $clone->setId('third');
        self::assertSame(['id' => 'third'], $clone->attributes($payload));
        self::assertSame(['id' => 'first'], $parser->attributes($payload));
        $parser->setId('fourth');
        self::assertSame(['id' => 'fourth'], $parser->attributes($payload));
        self::assertSame(2, $parser->validations);
    }

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
