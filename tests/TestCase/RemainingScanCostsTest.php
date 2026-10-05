<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstMerge;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RemainingScanCostsTest extends TestCase
{
    use ScalingGuardTrait;

    public function testBalancedDestinationsAndTitlesKeepTheirSpelling(): void
    {
        $converter = new CarveConverter();
        $this->assertStringContainsString('href="a(b)c" title="title)"', $converter->convert('[x](a(b)c "title)")'));
        $this->assertStringNotContainsString('<a ', $converter->convert(str_repeat('[x](', 20) . ')'));
    }

    public function testConcurrentDuplicateAdditionsKeepOccurrenceCounts(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $result = AstMerge::merge(
            $codec->encode($converter->parse('')),
            $codec->encode($converter->parse("a\n\na\n\nb\n")),
            $codec->encode($converter->parse("a\n\nb\n\nb\n")),
        );
        $this->assertTrue($result['ok']);
        $this->assertCount(4, $result['ast']['children']);
    }

    #[Group('scaling')]
    public function testDistantDestinationCloserScalesLinearly(): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), '[x](', ')', 'distant destination closer', 2000);
    }

    #[Group('scaling')]
    public function testWideCommentWalkScalesLinearly(): void
    {
        $method = new ReflectionMethod(CarveRenderer::class, 'holdsLineComment');
        $small = new Emphasis();
        $large = new Emphasis();
        for ($i = 0; $i < 16000; ++$i) {
            $large->appendChild(new Text('a'));
            if ($i < 4000) {
                $small->appendChild(new Text('a'));
            }
        }
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $method->invoke(null, strlen($source) === 4000 ? $small : $large),
            str_repeat('a', 4000),
            str_repeat('a', 16000),
            'wide comment walk',
            4000,
            16000,
        );
    }

    #[Group('scaling')]
    public function testDuplicateMergeBucketsScaleLinearly(): void
    {
        $method = new ReflectionMethod(AstMerge::class, 'matchSide');
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($method): void {
                $nodes = array_fill(0, strlen($source), ['type' => 'text', 'value' => 'a']);
                $method->invoke(null, $nodes, $nodes, '/children');
            },
            str_repeat('a', 4000),
            str_repeat('a', 16000),
            'duplicate merge buckets',
            4000,
            16000,
        );
    }

    #[Group('scaling')]
    public function testFailedLintDestinationsScaleLinearly(): void
    {
        $linter = new MarkdownHabitLinter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $linter->lint($source),
            str_repeat('[x](', 2000) . ')',
            str_repeat('[x](', 8000) . ')',
            'lint destinations',
            2000,
            8000,
        );
    }
}
