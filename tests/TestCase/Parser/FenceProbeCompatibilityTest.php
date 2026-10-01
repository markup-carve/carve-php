<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FenceProbeCompatibilityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, bool}>
     */
    public static function codeClosers(): array
    {
        return [
            'backticks' => ['```', '`', 3, true],
            'longer run' => ['`````', '`', 3, true],
            'shorter run' => ['``', '`', 3, false],
            'tilde padding' => ["~~~~ \t", '~', 3, true],
            'wrong character' => ['~~~', '`', 3, false],
            'leading space' => [' ```', '`', 3, false],
            'leading tab' => ["\t```", '`', 3, false],
            'mixed run' => ['``~', '`', 3, false],
            'text suffix' => ['``` end', '`', 3, false],
            'vertical tab suffix' => ["```\v", '`', 3, false],
            'form feed suffix' => ["```\f", '`', 3, false],
            'carriage return suffix' => ["```\r", '`', 3, false],
            'final newline' => ["```\n", '`', 3, true],
            'padded final newline' => ["``` \t\n", '`', 3, true],
            'two final newlines' => ["```\n\n", '`', 3, false],
            'empty line' => ['', '`', 3, false],
            'nonstandard character' => ['%%% ', '%', 3, true],
            'regex punctuation' => ['|||', '|', 3, true],
            'multiple character argument' => ['abbb', 'ab', 3, true],
        ];
    }

    #[DataProvider('codeClosers')]
    public function testCodeCloserCompatibility(string $line, string $char, int $length, bool $expected): void
    {
        self::assertSame($expected, (new FencedBlockParser())->isCodeFenceCloser($line, $char, $length));
    }

    public function testFenceIndexKeepsWidthsAndLastCloserPositions(): void
    {
        $scanner = (new ReflectionMethod(BlockParser::class, 'continuationsMapper'))->invoke(new BlockParser());
        self::assertSame([
            'comment' => [3 => 4],
            'colon' => [4 => 3],
            'code' => [
                '~' => ['runs' => [3], 'lastAtLeast' => [2]],
                '`' => ['runs' => [4, 5], 'lastAtLeast' => [7, 7]],
            ],
        ], $scanner->fenceCloserIndex([
            '~~~',
            '````',
            '  ~~~',
            "\t:::: ",
            '  %%% comment',
            'ordinary prose',
            "```\v",
            "  `````\t",
            '::: text',
            '```~',
        ]));
    }

    public function testFenceIndexSeesAReplacedSubclassHelper(): void
    {
        $parser = new class extends BlockParser {
            public function replaceHelper(FencedBlockParser $helper): void
            {
                $this->fencedBlockParser = $helper;
            }
        };
        $second = new class extends FencedBlockParser {
            public function parseFencedCommentOpenerAnyColumn(string $line): ?array
            {
                return $line === 'BETA' ? ['fence' => '%%%%', 'length' => 4, 'tail' => ''] : null;
            }
        };
        $first = new class (static function () use ($parser, $second): void {
            $parser->replaceHelper($second);
        }) extends FencedBlockParser {
            public function __construct(private Closure $replace)
            {
            }

            public function parseFencedCommentOpenerAnyColumn(string $line): ?array
            {
                ($this->replace)();

                return $line === 'ALPHA' ? ['fence' => '%%%', 'length' => 3, 'tail' => ''] : null;
            }
        };
        $parser->replaceHelper($first);
        $scanner = (new ReflectionMethod(BlockParser::class, 'continuationsMapper'))->invoke($parser);
        self::assertSame([
            'comment' => [3 => 0, 4 => 1],
            'colon' => [],
            'code' => [],
        ], $scanner->fenceCloserIndex(['ALPHA', 'BETA', 'ordinary prose']));
    }
}
