<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\Include\IncludeResolver;
use MarkupCarve\Carve\InputError;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Layout\LayoutError;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Output\OutputWriter;
use MarkupCarve\Carve\Output\WriterRegistry;
use MarkupCarve\Carve\RenderError;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase
{
    /**
     * @var string
     */
    private const EMBED = "Watch:\n\n```embed\nhttps://youtu.be/abc\n```\n";

    public function testParseAndRenderTakeSourceOrTree(): void
    {
        $tree = Carve::parse("# T\n\n[T][]\n", ['headingIds' => 'lower']);
        $this->assertSame('document', $tree->type);
        $want = "<h1 id=\"t\">T</h1>\n<p><a href=\"#t\">T</a></p>";
        $this->assertSame($want, Carve::render("# T\n\n[T][]\n", ['headingIds' => 'lower', 'sections' => false]));
        $this->assertSame($want, Carve::render($tree, ['headingIds' => 'lower', 'sections' => false]));
        $decoded = Carve::decodeTree(Carve::encodeTree($tree));
        $this->assertSame($want, Carve::render($decoded, ['headingIds' => 'lower', 'sections' => false]));
        $array = json_decode(Carve::toJson("# T\n\n[T][]\n"), true);
        $this->assertIsArray($array);
        $this->assertSame($want, Carve::render($array, ['headingIds' => 'lower', 'sections' => false]));
    }

    public function testRenderOfAParsedTreeKeepsSourceLinesAndLeavesTheTreeAlone(): void
    {
        $tree = Carve::parse("a\n\nb\n");
        $before = Carve::encodeTree($tree);
        $want = "<p data-source-line=\"1\">a</p>\n<p data-source-line=\"3\">b</p>";
        $this->assertSame($want, Carve::render($tree, ['sourceLines' => true]));
        $this->assertSame($before, Carve::encodeTree($tree));
    }

    public function testRenderWithDiagnosticsOfSourceAndTree(): void
    {
        $result = Carve::renderWithDiagnostics('{{ a.crv }}');
        $this->assertSame(['include-no-resolver', 'include-unresolved'], $result->codes());
        $this->assertSame(['parse', 'render-loss'], array_map(static fn ($d) => $d->channel, $result->diagnostics));

        $tree = Carve::parse('{{ a.crv }}');
        $this->assertSame(['include-unresolved'], Carve::renderWithDiagnostics($tree)->codes());
    }

    public function testStrictLossesOnATree(): void
    {
        $this->expectException(RenderError::class);
        Carve::render(Carve::parse('{{ a.crv }}'), ['strictLosses' => true]);
    }

    public function testParseWithDiagnosticsAndLayout(): void
    {
        $parsed = Carve::parseWithDiagnostics("{#a}\nx\n\n{#a}\ny\n");
        $this->assertSame(['duplicate-id'], $parsed->codes());
        $layout = Carve::parseWithLayout("- a\n");
        $this->assertSame('document', $layout->tree->type);
        $this->assertIsArray($layout->layout);
        $json = json_encode($layout->layout);
        $this->assertIsString($json);
        $this->assertIsArray(Carve::validateLayout($json));
    }

    public function testLintFormatAndWrite(): void
    {
        $this->assertSame(['duplicate-id'], array_map(static fn ($d) => $d->code, Carve::lint("{#a}\nx\n\n{#a}\ny\n")));
        $this->assertSame("a\nb\n", Carve::format("a  \nb\n"));
        $this->assertSame([], Carve::formatWithDiagnostics("a\n")->diagnostics);
        $tree = Carve::parse("| a |\n|---|\n| 1 |\n");
        $this->assertSame(Carve::write(json_decode(Carve::encodeTree($tree), true)), Carve::write($tree));
        $this->assertSame([], Carve::writeWithDiagnostics($tree)->diagnostics);
    }

    public function testImportDocument(): void
    {
        $result = Carve::importDocument('<p>Hi <em>there</em></p>', ['from' => 'html']);
        $this->assertSame("Hi /there/\n", $result->source);
        $this->assertSame($result->source, Carve::importHtml('<p>Hi <em>there</em></p>')->source);
    }

    public function testEnvelopeRoundTrip(): void
    {
        $tree = Carve::parse("a\n");
        $envelope = json_decode(Carve::encodeEnvelope($tree), true);
        $this->assertIsArray($envelope);
        $this->assertSame('2.0', $envelope['astVersion']);
        $this->assertEquals(json_decode(Carve::encodeTree($tree), true), $envelope['document']);
        $this->assertSame(Carve::encodeTree($tree), Carve::encodeTree(Carve::decodeTree(Carve::encodeEnvelope($tree))));
        Carve::validateTree($tree);
        Carve::validateTree(['type' => 'document', 'astVersion' => '2.0', 'children' => []]);
        $this->expectException(TreeError::class);
        Carve::validateTree(['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'nope']]]);
    }

    public function testIncludeResolverAndDependencies(): void
    {
        $dir = sys_get_temp_dir() . '/carve-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/part.crv', "Part *one*.\n");
        $source = "{{ part.crv }}\n\n{{ missing.crv }}\n";

        $resolver = FileResolver::forRoot($dir);
        $this->assertInstanceOf(IncludeResolver::class, $resolver);
        $this->assertStringContainsString('<strong>one</strong>', Carve::render($source, ['includes' => $resolver]));
        $this->assertStringContainsString('<strong>one</strong>', Carve::render($source, ['includes' => ['resolver' => $resolver]]));

        $want = [
            ['path' => 'part.crv', 'from' => null, 'file' => 'part.crv', 'read' => true],
            ['path' => 'missing.crv', 'from' => null, 'file' => null, 'read' => false],
        ];
        $this->assertSame($want, Carve::includeDependencies($source, ['includes' => $resolver]));
        $this->assertSame($want, Carve::includeDependencies(Carve::parse($source, ['includes' => $resolver])));

        $host = new class implements IncludeResolver {
            public function resolve(array $request): array
            {
                return ['text' => 'From ' . $request['path'] . "\n", 'id' => 'mem:' . $request['path']];
            }
        };
        $this->assertSame('<p>From x.crv</p>', Carve::render('{{ x.crv }}', ['includes' => $host]));
    }

    public function testExtensionProviderRegistersAMediaEmbed(): void
    {
        $embed = self::embed();
        $want = "<p>Watch:</p>\n<iframe src=\"https://youtu.be/abc\"></iframe>";
        $this->assertSame($want, Carve::render(self::EMBED, ['extensions' => [$embed]]));
        $tree = Carve::parse(self::EMBED, ['extensions' => [$embed]]);
        $this->assertSame($want, Carve::render($tree, ['extensions' => [$embed]]));
        $decoded = Carve::decodeTree(Carve::encodeTree($tree), ['extensions' => [$embed]]);
        $this->assertSame($want, Carve::render($decoded, ['extensions' => [$embed]]));
        // Without the renderer the fallback, the core code block, is written.
        $this->assertStringContainsString('<code class="language-embed">', Carve::render($tree));
        $this->assertSame(self::EMBED, Carve::write($tree, null, ['extensions' => [$embed]]));
        $this->assertSame(self::EMBED, Carve::format(self::EMBED, ['extensions' => [$embed]]));
        $this->assertSame([], Carve::lint(self::EMBED, ['extensions' => [$embed]]));
        $this->assertStringContainsString('<input type="radio"', Carve::render(
            ":::: tabs\n::: tab \"A\"\nx\n:::\n::::\n",
            ['extensions' => Tabs::extensions(true)],
        ));
    }

    public function testARequiredExtensionIsReadWhenItsVersionIsRegistered(): void
    {
        $embed = self::embed();
        $tree = json_decode(Carve::encodeTree(Carve::parse(self::EMBED, ['extensions' => [$embed]])), true);
        $this->assertIsArray($tree);
        $tree['extensions'][0]['required'] = true;
        $json = (string)json_encode($tree);
        $want = "<p>Watch:</p>\n<iframe src=\"https://youtu.be/abc\"></iframe>";
        $this->assertSame($want, Carve::render(Carve::decodeTree($json, ['extensions' => [$embed]]), ['extensions' => [$embed]]));
        Carve::validateTree($tree, ['extensions' => [$embed]]);
        $this->assertSame($want, Carve::render($tree, ['extensions' => [$embed]]));
        $this->expectException(TreeError::class);
        Carve::decodeTree($json);
    }

    public function testEveryParseResultKeepsItsSourceForLaterCalls(): void
    {
        $want = "<p data-source-line=\"1\">a</p>\n<p data-source-line=\"3\">b</p>";
        $this->assertSame($want, Carve::render(Carve::parseWithDiagnostics("a\n\nb\n")->tree, ['sourceLines' => true]));
        $this->assertSame($want, Carve::render(Carve::parseWithLayout("a\n\nb\n")->tree, ['sourceLines' => true]));
        $this->assertSame(Carve::toJson("a\n"), Carve::encodeTree(Carve::parseWithDiagnostics("a\n")->tree));
    }

    public function testWriterRegistry(): void
    {
        $registry = Carve::writers();
        $this->assertSame(['html', 'carve', 'markdown', 'plain', 'ansi'], $registry->targets());
        $tree = Carve::parse("Some *x*\n");
        $this->assertSame('<p>Some <strong>x</strong></p>', Carve::writeTo('html', $tree));
        $this->assertSame("Some *x*\n", Carve::writeTo('carve', $tree));

        $stub = new class implements OutputWriter {
            public function target(): string
            {
                return 'text';
            }

            public function write(Node $tree, array $options = []): string
            {
                $upper = ($options['upper'] ?? false) === true;
                $text = $tree->type . ':' . count($tree->children ?? []);

                return $upper ? strtoupper($text) : $text;
            }
        };
        $registry->register($stub);
        $this->assertSame(['html', 'carve', 'markdown', 'plain', 'ansi', 'text'], $registry->targets());
        $this->assertTrue($registry->has('text'));
        $this->assertSame('DOCUMENT:1', Carve::writeTo('text', $tree, ['upper' => true], $registry));
        $this->assertSame('document:1', (new WriterRegistry([$stub]))->write('text', $tree));
        $this->assertFalse(Carve::writers()->has('text'));

        $this->assertSame("Some **x**\n", Carve::writeTo('markdown', $tree));
        $this->expectException(OptionError::class);
        $this->expectExceptionMessage('unknown output target pdf; known targets: html, carve, markdown, plain, ansi');
        Carve::writeTo('pdf', $tree);
    }

    /**
     * @return array<string, array{\Closure(): mixed, class-string<\Throwable>}>
     */
    public static function refusals(): array
    {
        return [
            'unknown option' => [static fn () => Carve::parse('a', ['nope' => true]), OptionError::class],
            'render option on parse' => [static fn () => Carve::parse('a', ['sections' => false]), OptionError::class],
            'bad boolean' => [static fn () => Carve::render('a', ['sections' => 'no']), OptionError::class],
            'bad enum' => [static fn () => Carve::render('a', ['profile' => 'huge']), OptionError::class],
            'bad extensions' => [static fn () => Carve::render('a', ['extensions' => ['x']]), OptionError::class],
            'bad includes' => [static fn () => Carve::render('a', ['includes' => 'dir']), OptionError::class],
            'relative root' => [static fn () => FileResolver::forRoot('rel'), OptionError::class],
            'lint rule' => [static fn () => Carve::lint('a', ['rules' => ['nope' => 'on']]), OptionError::class],
            'import format' => [static fn () => Carve::importDocument('<p>a</p>', ['from' => 'rst']), OptionError::class],
            'decode option' => [static fn () => Carve::decodeTree('{}', ['sections' => true]), OptionError::class],
            'profile length' => [static fn () => Carve::render(str_repeat('a', 100001), ['profile' => 'comment']), InputError::class],
            'strict losses' => [static fn () => Carve::toHtml('{{ a.crv }}', ['strictLosses' => true]), RenderError::class],
            'not JSON' => [static fn () => Carve::decodeTree('{'), TreeError::class],
            'bad tree' => [static fn () => Carve::render(['type' => 'nope']), TreeError::class],
            'bad layout' => [static fn () => Carve::validateLayout('{'), LayoutError::class],
            'unspellable' => [
                static fn () => Carve::write([
                    'type' => 'document',
                    'astVersion' => '2.0',
                    'children' => [['type' => 'raw_inline', 'format' => 'html', 'value' => 'x']],
                ]),
                CarveWriteError::class,
            ],
        ];
    }

    /**
     * @param \Closure(): mixed $call
     * @param class-string<\Throwable> $error
     *
     * @return void
     */
    #[DataProvider('refusals')]
    public function testEveryRefusalIsATypedCarveException(Closure $call, string $error): void
    {
        try {
            $call();
            $this->fail('no error thrown');
        } catch (CarveException $e) {
            $this->assertInstanceOf($error, $e);
        }
    }

    public function testEveryPublicErrorImplementsTheMarker(): void
    {
        $errors = [
            OptionError::class,
            InputError::class,
            RenderError::class,
            CarveWriteError::class,
            TreeError::class,
            LayoutError::class,
            ImportInputError::class,
        ];
        foreach ($errors as $class) {
            $this->assertTrue(is_subclass_of($class, CarveException::class), $class);
        }
    }

    private static function embed(): ExtensionProvider
    {
        return new class implements ExtensionProvider {
            public function definitions(): array
            {
                return [
                    new ExtensionDefinition(
                        'media.embed',
                        '1',
                        ['caption'],
                        matcher: ['form' => 'fence', 'name' => 'embed'],
                        transform: static function (Claim $claim): Node {
                            $node = new Node('block_extension', []);
                            $node->f['name'] = 'media.embed';
                            $node->f['version'] = '1';
                            $node->f['slots'] = ['caption' => []];
                            $node->f['props'] = ['url' => trim($claim->source['body'] ?? '')];
                            $node->f['fallback'] = [$claim->copyNode()];

                            return $node;
                        },
                        renderer: static function (Node $node, RenderHost $host): string {
                            $props = $node->f['props'] ?? [];
                            $url = is_array($props) && is_string($props['url'] ?? null) ? $props['url'] : '';

                            return '<iframe src="' . RenderHost::escapeAttribute($url) . '"></iframe>';
                        },
                    ),
                ];
            }
        };
    }
}
