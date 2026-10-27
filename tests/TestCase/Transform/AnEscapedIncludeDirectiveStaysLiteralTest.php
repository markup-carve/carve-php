<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A backslash makes the opening brace literal, so `\{{ path }}` is no directive
 * (CARVE-P7-004, spec PART 6).
 */
class AnEscapedIncludeDirectiveStaysLiteralTest extends TestCase
{
    #[DataProvider('escapedProvider')]
    public function testAnEscapedDirectiveIsNotExpanded(string $source): void
    {
        $result = $this->expand($source);

        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), preg_replace('/\n\z/', '', $result['html']));
        $this->assertSame([], $result['calls']);
        $this->assertSame([], $result['dependencies']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapedProvider(): iterable
    {
        yield 'alone on its line' => ['\{{ c.crv }}'];
        yield 'inside a sentence' => ['See \{{ c.crv }} here.'];
        yield 'with a section and an option' => ['See \{{ c.crv #pick @shift:1 }} here.'];
    }

    public function testALiveDirectiveAfterAnEscapedOneStillExpands(): void
    {
        $result = $this->expand('See \{{ a {{ c.crv }} here.');

        $this->assertSame(['c.crv'], $result['calls']);
        $this->assertSame("<p>See {{ a body here.</p>\n", $result['html']);
    }

    public function testADirectiveAfterAnEscapedLoneBraceExpands(): void
    {
        $result = $this->expand('See \{{{ c.crv }} here.');

        $this->assertSame(['c.crv'], $result['calls']);
        $this->assertSame("<p>See {body here.</p>\n", $result['html']);
    }

    #[DataProvider('writerProvider')]
    public function testTheWriterKeepsTheEscape(string $source): void
    {
        $carve = CarveConverter::carve();
        $written = $carve->render($carve->parse($source));

        $this->assertSame($source . "\n", $written);
        $this->assertSame([], $this->expand($written)['calls']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writerProvider(): iterable
    {
        yield 'alone on its line' => ['\{{ c.crv }}'];
        yield 'inside a sentence' => ['See \{{ c.crv }} here.'];
        yield 'after a brace pair that opens the run' => ['{{ \{{ c.crv }}'];
    }

    public function testTheWriterKeepsALiveDirectiveAfterAnEscapedOne(): void
    {
        $source = 'See \{{ a {{ c.crv }} here.';
        $carve = CarveConverter::carve();
        $written = $carve->render($carve->parse($source));

        $this->assertSame($source . "\n", $written);
        $this->assertSame(['c.crv'], $this->expand($written)['calls']);
    }

    /**
     * @return array{html: string, calls: list<string>, dependencies: array<mixed>, warnings: array<mixed>}
     */
    protected function expand(string $source): array
    {
        $resolver = new class implements IncludeResolverInterface {
            /**
             * @var list<string>
             */
            public array $calls = [];

            public function resolve(string $path, IncludeContext $context): ?string
            {
                $this->calls[] = $path;

                return $path === 'c.crv' ? "body\n" : null;
            }
        };
        $converter = new CarveConverter();
        $expander = new IncludeExpander($resolver);
        $html = $converter->render($converter->transform($converter->parse($source), $expander));

        return [
            'html' => $html,
            'calls' => $resolver->calls,
            'dependencies' => $expander->getDependencies(),
            'warnings' => $expander->getWarnings(),
        ];
    }
}
