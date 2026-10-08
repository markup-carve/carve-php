<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A quoted include path decodes `\"` and `\\` and nothing else (#2948).
 */
class AQuotedIncludePathDecodesOnlyTheQuoteAndTheBackslashTest extends TestCase
{
    #[DataProvider('pathProvider')]
    public function testTheQuotedPathDecodesOnlyTwoPairs(string $source, string $expected): void
    {
        $parsed = IncludeDirectiveSyntax::parse($source);

        $this->assertIsArray($parsed, $source);
        $this->assertSame($expected, $parsed['path'], $source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathProvider(): iterable
    {
        yield 'an escaped quote is a quote' => ['{{ "a\"b.crv" }}', 'a"b.crv'];
        yield 'an escaped backslash is one backslash' => ['{{ "a\\\\b.crv" }}', 'a\b.crv'];
        yield 'a C escape stays verbatim' => ['{{ "notes\new.crv" }}', 'notes\new.crv'];
        yield 'a backslash before punctuation stays verbatim' => ['{{ "part\.crv" }}', 'part\.crv'];
        yield 'an octal escape stays verbatim' => ['{{ "a\101.crv" }}', 'a\101.crv'];
    }

    public function testTheExpanderResolvesTheVerbatimPath(): void
    {
        $converter = CarveConverter::carve();
        $expander = new IncludeExpander($this->resolver(['notes\new.crv' => "included text\n"]));
        $carve = $converter->render($converter->transform($converter->parse('{{ "notes\new.crv" }}'), $expander));

        $this->assertSame("included text\n", $carve);
        $this->assertSame([], $expander->getWarnings());
    }

    /**
     * @param array<string, string> $files
     *
     * @throws \RuntimeException
     */
    protected function resolver(array $files): IncludeResolverInterface
    {
        return new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                if (!array_key_exists($path, $this->files)) {
                    throw new RuntimeException("Missing include: {$path}");
                }

                return $this->files[$path];
            }
        };
    }
}
