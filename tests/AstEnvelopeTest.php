<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstEnvelope;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Exception\AstEnvelopeException;
use MarkupCarve\Carve\Exception\AstEnvelopeExtensionException;
use MarkupCarve\Carve\Exception\AstEnvelopeShapeException;
use MarkupCarve\Carve\Exception\AstEnvelopeVersionException;
use MarkupCarve\Carve\Exception\AstEnvelopeVocabularyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#2453, PART 12 §34.
 *
 * The envelope exists so a reader can name WHICH of three failures it hit,
 * so most of this file is about which exception a payload produces rather
 * than about the tree, which the codec's own tests already cover.
 */
class AstEnvelopeTest extends TestCase
{
    private AstEnvelope $envelope;

    protected function setUp(): void
    {
        $this->envelope = new AstEnvelope();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function wrap(array $overrides = []): array
    {
        $document = (new AstCodec())->encode(CarveConverter::create()->parse("a\n"));

        return ['astVersion' => '1.0', 'document' => $document, ...$overrides];
    }

    public function testEncodeWrapsTheTreeWithoutMovingIt(): void
    {
        $parsed = CarveConverter::create()->parse("a\n");
        $envelope = $this->envelope->encode($parsed);

        $this->assertSame('1.0', $envelope['astVersion']);
        $this->assertSame((new AstCodec())->encode($parsed), $envelope['document']);
        $this->assertSame(['astVersion', 'document'], array_keys($envelope));
    }

    public function testEncodeCarriesAVocabularyAndExtensionsOnlyWhenGiven(): void
    {
        $parsed = CarveConverter::create()->parse("a\n");
        $envelope = $this->envelope->encode($parsed, AstEnvelope::CORE_VOCABULARY, [
            ['id' => 'https://markup-carve.org/ext/citations', 'version' => '1'],
        ]);

        $this->assertSame(AstEnvelope::CORE_VOCABULARY, $envelope['vocabulary']);
        $this->assertSame('https://markup-carve.org/ext/citations', $envelope['extensions'][0]['id']);
    }

    public function testRoundTripReturnsTheSameTree(): void
    {
        $parsed = CarveConverter::create()->parse("# h\n\ntext\n");
        $envelope = $this->envelope->encode($parsed);

        $back = $this->envelope->decode($envelope);

        $this->assertSame(
            (new AstCodec())->encode($parsed),
            (new AstCodec())->encode($back),
        );
    }

    public function testAHigherMajorIsRefusedByVersionNotByTheSchema(): void
    {
        // §34(a): the payload may be well-formed under a contract this build
        // predates, so reporting it as invalid sends the caller hunting a
        // corrupt tree.
        try {
            $this->envelope->decode($this->wrap(['astVersion' => '2.0']));
            $this->fail('expected a version refusal');
        } catch (AstEnvelopeVersionException $e) {
            $this->assertSame('2.0', $e->found);
            $this->assertSame('1.0', $e->implemented);
        }
    }

    public function testAHigherMinorIsAccepted(): void
    {
        $document = $this->envelope->decode($this->wrap(['astVersion' => '1.7']));

        $this->assertNotEmpty($document->getChildren());
    }

    public function testAHigherMinorIsStillRefusedWhenItRequiresAnUnknownExtension(): void
    {
        $this->expectException(AstEnvelopeExtensionException::class);
        $this->envelope->decode($this->wrap([
            'astVersion' => '1.7',
            'extensions' => [['id' => 'https://example.test/ext/unknown']],
        ]));
    }

    public function testAnExtensionTheReaderImplementsPasses(): void
    {
        $document = $this->envelope->decode(
            $this->wrap(['extensions' => [['id' => 'https://example.test/ext/known']]]),
            ['https://example.test/ext/known'],
        );

        $this->assertNotEmpty($document->getChildren());
    }

    public function testAnExtensionMarkedNotRequiredIsIgnored(): void
    {
        // Absent means true, so only an explicit false may be skipped.
        $document = $this->envelope->decode($this->wrap([
            'extensions' => [['id' => 'https://example.test/ext/optional', 'required' => false]],
        ]));

        $this->assertNotEmpty($document->getChildren());
    }

    public function testAnAbsentVocabularyMeansTheCoreOne(): void
    {
        $document = $this->envelope->decode($this->wrap(['vocabulary' => AstEnvelope::CORE_VOCABULARY]));

        $this->assertNotEmpty($document->getChildren());
        $this->assertNotEmpty($this->envelope->decode($this->wrap())->getChildren());
    }

    public function testAForeignVocabularyIsRefusedRatherThanWalked(): void
    {
        try {
            $this->envelope->decode($this->wrap(['vocabulary' => 'https://example.test/vocab']));
            $this->fail('expected a vocabulary refusal');
        } catch (AstEnvelopeVocabularyException $e) {
            $this->assertSame('https://example.test/vocab', $e->vocabulary);
        }

        $this->assertNotEmpty($this->envelope->decode(
            $this->wrap(['vocabulary' => 'https://example.test/vocab']),
            [],
            ['https://example.test/vocab'],
        )->getChildren());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedEnvelopes(): array
    {
        return [
            'unnamed field' => [['nope' => 1], 'which the schema does not name'],
            'no version' => [['astVersion' => null], '"astVersion" is missing'],
            'leading zero' => [['astVersion' => '0.9'], 'no leading zero'],
            'not major.minor' => [['astVersion' => '1'], 'no leading zero'],
            'vocabulary not a string' => [['vocabulary' => 7], '"vocabulary" is not a string'],
            'extensions not a list' => [['extensions' => ['id' => 'x']], '"extensions" is not an array'],
            'extension not an object' => [['extensions' => ['x']], 'extension 0 is not an object'],
            'extension unnamed field' => [['extensions' => [['id' => 'x', 'nope' => 1]]], 'does not name'],
            'extension without id' => [['extensions' => [['version' => '1']]], 'has no "id"'],
            'extension version not a string' => [['extensions' => [['id' => 'x', 'version' => 1]]], 'non-string'],
            'extension required not a bool' => [['extensions' => [['id' => 'x', 'required' => 'yes']]], 'non-boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param string $needle
     */
    #[DataProvider('malformedEnvelopes')]
    public function testAMalformedEnvelopeIsAShapeFailure(array $overrides, string $needle): void
    {
        $this->expectException(AstEnvelopeShapeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');
        $this->envelope->decode($this->wrap($overrides));
    }

    public function testAMissingDocumentIsAShapeFailure(): void
    {
        $this->expectException(AstEnvelopeShapeException::class);
        $this->envelope->decode(['astVersion' => '1.0']);
    }

    public function testAnEnvelopeFailureIsNotADecodeFailure(): void
    {
        // The whole point of §34: "this envelope says something I cannot
        // honour" and "this tree is not readable" are different catches.
        $this->expectException(AstEnvelopeException::class);
        try {
            $this->envelope->decode($this->wrap(['astVersion' => '2.0']));
        } catch (AstDecodeException $e) {
            $this->fail('an envelope refusal must not arrive as an AST decode failure');
        }
    }

    public function testABrokenTreeInsideAGoodEnvelopeIsStillADecodeFailure(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->envelope->decode([
            'astVersion' => '1.0',
            'document' => ['type' => 'document', 'children' => [['type' => 'no-such-node']]],
        ]);
    }
}
