<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\TestCase;

class RenderLossIncludesTest extends TestCase
{
    public function testCliPrintsChildLocationAndReportsIncludedLoss(): void
    {
        $root = sys_get_temp_dir() . '/carve-render-loss-' . bin2hex(random_bytes(5));
        mkdir($root . '/snippets', 0777, true);
        $doc = $root . '/d.crv';
        $child = $root . '/snippets/div.crv';
        file_put_contents($doc, "Intro.\n\n{{ snippets/div.crv }}\n");
        file_put_contents($child, "{.box}\n:::\nx\n:::\n");
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/carve', '--md', '--include-root', $root, $doc, '--report-losses', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = (string)stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertStringContainsString("snippets/div.crv:2:1 markdown-container-flattened - Flattened a container into Markdown blocks (included from {$doc}:3:1)", $errors);
            $report = json_decode(substr($errors, (int)strpos($errors, '{')), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($report['losses']);
            foreach ($report['losses'] as $loss) {
                self::assertSame('snippets/div.crv', $loss['file']);
                self::assertSame([['file' => $doc, 'line' => 3, 'column' => 1]], $loss['includedBy']);
                self::assertArrayNotHasKey('file', $loss['pos']);
            }
        } finally {
            unlink($child);
            unlink($doc);
            rmdir($root . '/snippets');
            rmdir($root);
        }
    }

    public function testNestedChainAndSelectedLineReachCheckedResultAndStrictError(): void
    {
        $files = ['b.crv' => "{{ c.crv @lines:3-6 }}\n", 'c.crv' => "before\n\n{.box}\n:::\nx\n:::\n"];
        $resolver = new class ($files) implements IncludeResolverInterface {
            public function __construct(private array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                return isset($this->files[$path]) ? new ResolvedInclude($this->files[$path], $path) : null;
            }
        };
        $converter = CarveConverter::markdown();
        $converter->getParser()->enablePositionTracking();
        $source = "{{ b.crv }}\n";
        $document = $converter->transform($converter->parse($source), new IncludeExpander($resolver, 'a.crv', source: $source));
        $report = $converter->renderWithReport($document);
        $loss = array_values(array_filter($report->losses, static fn (array $item): bool => $item['code'] === 'markdown-container-flattened'))[0];
        self::assertSame('c.crv', $loss['file']);
        self::assertSame(4, $loss['pos']['startLine']);
        self::assertSame([['file' => 'a.crv', 'line' => 1, 'column' => 1], ['file' => 'b.crv', 'line' => 1, 'column' => 1]], $loss['includedBy']);
        self::assertArrayNotHasKey('file', $loss['pos']);
        try {
            $converter->renderWithReport($document, true);
            self::fail('Strict rendering should refuse the losses.');
        } catch (RenderLossException $error) {
            self::assertSame($report->losses, $error->losses);
        }
    }

    public function testTopLevelLossHasNoIncludeFields(): void
    {
        $report = CarveConverter::markdown()->convertWithReport(":::\nx\n:::\n");
        self::assertNotEmpty($report->losses);
        foreach ($report->losses as $loss) {
            self::assertArrayNotHasKey('file', $loss);
            self::assertArrayNotHasKey('includedBy', $loss);
            self::assertArrayNotHasKey('file', $loss['pos']);
        }
    }
}
