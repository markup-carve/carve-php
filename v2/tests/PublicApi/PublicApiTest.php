<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\PublicApi;

use Closure;
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\CarveException;
use MarkupCarve\Carve2\Extension\Claim;
use MarkupCarve\Carve2\Extension\ExtensionDefinition;
use MarkupCarve\Carve2\Extension\ExtensionProvider;
use MarkupCarve\Carve2\Extension\RenderHost;
use MarkupCarve\Carve2\Extension\Tabs;
use MarkupCarve\Carve2\Import\ImportInputError;
use MarkupCarve\Carve2\Include\FileResolver;
use MarkupCarve\Carve2\Include\IncludeResolver;
use MarkupCarve\Carve2\InputError;
use MarkupCarve\Carve2\Json\TreeError;
use MarkupCarve\Carve2\Layout\LayoutError;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\OptionError;
use MarkupCarve\Carve2\Output\OutputWriter;
use MarkupCarve\Carve2\Output\WriterRegistry;
use MarkupCarve\Carve2\RenderError;
use MarkupCarve\Carve2\Writer\CarveWriteError;
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
        $tree = Carve2::parse("# T\n\n[T][]\n", ['headingIds' => 'lower']);
        $this->assertSame('document', $tree->type);
        $want = "<h1 id=\"t\">T</h1>\n<p><a href=\"#t\">T</a></p>";
        $this->assertSame($want, Carve2::render("# T\n\n[T][]\n", ['headingIds' => 'lower', 'sections' => false]));
        $this->assertSame($want, Carve2::render($tree, ['headingIds' => 'lower', 'sections' => false]));
        $decoded = Carve2::decodeTree(Carve2::encodeTree($tree));
        $this->assertSame($want, Carve2::render($decoded, ['headingIds' => 'lower', 'sections' => false]));
        $array = json_decode(Carve2::toJson("# T\n\n[T][]\n"), true);
        $this->assertIsArray($array);
        $this->assertSame($want, Carve2::render($array, ['headingIds' => 'lower', 'sections' => false]));
    }

    public function testRenderOfAParsedTreeKeepsSourceLinesAndLeavesTheTreeAlone(): void
    {
        $tree = Carve2::parse("a\n\nb\n");
        $before = Carve2::encodeTree($tree);
        $want = "<p data-source-line=\"1\">a</p>\n<p data-source-line=\"3\">b</p>";
        $this->assertSame($want, Carve2::render($tree, ['sourceLines' => true]));
        $this->assertSame($before, Carve2::encodeTree($tree));
    }

    public function testRenderWithDiagnosticsOfSourceAndTree(): void
    {
        $result = Carve2::renderWithDiagnostics('{{ a.crv }}');
        $this->assertSame(['include-no-resolver', 'include-unresolved'], $result->codes());
        $this->assertSame(['parse', 'render-loss'], array_map(static fn ($d) => $d->channel, $result->diagnostics));

        $tree = Carve2::parse('{{ a.crv }}');
        $this->assertSame(['include-unresolved'], Carve2::renderWithDiagnostics($tree)->codes());
    }

    public function testStrictLossesOnATree(): void
    {
        $this->expectException(RenderError::class);
        Carve2::render(Carve2::parse('{{ a.crv }}'), ['strictLosses' => true]);
    }

    public function testParseWithDiagnosticsAndLayout(): void
    {
        $parsed = Carve2::parseWithDiagnostics("{#a}\nx\n\n{#a}\ny\n");
        $this->assertSame(['duplicate-id'], $parsed->codes());
        $layout = Carve2::parseWithLayout("- a\n");
        $this->assertSame('document', $layout->tree->type);
        $this->assertIsArray($layout->layout);
        $json = json_encode($layout->layout);
        $this->assertIsString($json);
        $this->assertIsArray(Carve2::validateLayout($json));
    }

    public function testLintFormatAndWrite(): void
    {
        $this->assertSame(['duplicate-id'], array_map(static fn ($d) => $d->code, Carve2::lint("{#a}\nx\n\n{#a}\ny\n")));
        $this->assertSame("a\nb\n", Carve2::format("a  \nb\n"));
        $this->assertSame([], Carve2::formatWithDiagnostics("a\n")->diagnostics);
        $tree = Carve2::parse("| a |\n|---|\n| 1 |\n");
        $this->assertSame(Carve2::write(json_decode(Carve2::encodeTree($tree), true)), Carve2::write($tree));
        $this->assertSame([], Carve2::writeWithDiagnostics($tree)->diagnostics);
    }

    public function testImportDocument(): void
    {
        $result = Carve2::importDocument('<p>Hi <em>there</em></p>', ['from' => 'html']);
        $this->assertSame("Hi /there/\n", $result->source);
        $this->assertSame($result->source, Carve2::importHtml('<p>Hi <em>there</em></p>')->source);
    }

    public function testEnvelopeRoundTrip(): void
    {
        $tree = Carve2::parse("a\n");
        $envelope = json_decode(Carve2::encodeEnvelope($tree), true);
        $this->assertIsArray($envelope);
        $this->assertSame('2.0', $envelope['astVersion']);
        $this->assertSame(json_decode(Carve2::encodeTree($tree), true), $envelope['document']);
        $this->assertSame(Carve2::encodeTree($tree), Carve2::encodeTree(Carve2::decodeTree(Carve2::encodeEnvelope($tree))));
        Carve2::validateTree($tree);
        Carve2::validateTree(['type' => 'document', 'astVersion' => '2.0', 'children' => []]);
        $this->expectException(TreeError::class);
        Carve2::validateTree(['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'nope']]]);
    }

    public function testIncludeResolverAndDependencies(): void
    {
        $dir = sys_get_temp_dir() . '/carve2-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/part.crv', "Part *one*.\n");
        $source = "{{ part.crv }}\n\n{{ missing.crv }}\n";

        $resolver = FileResolver::forRoot($dir);
        $this->assertInstanceOf(IncludeResolver::class, $resolver);
        $this->assertStringContainsString('<strong>one</strong>', Carve2::render($source, ['includes' => $resolver]));
        $this->assertStringContainsString('<strong>one</strong>', Carve2::render($source, ['includes' => ['resolver' => $resolver]]));

        $want = [
            ['path' => 'part.crv', 'from' => null, 'file' => 'part.crv', 'read' => true],
            ['path' => 'missing.crv', 'from' => null, 'file' => null, 'read' => false],
        ];
        $this->assertSame($want, Carve2::includeDependencies($source, ['includes' => $resolver]));
        $this->assertSame($want, Carve2::includeDependencies(Carve2::parse($source, ['includes' => $resolver])));

        $host = new class implements IncludeResolver {
            public function resolve(array $request): array
            {
                return ['text' => 'From ' . $request['path'] . "\n", 'id' => 'mem:' . $request['path']];
            }
        };
        $this->assertSame('<p>From x.crv</p>', Carve2::render('{{ x.crv }}', ['includes' => $host]));
    }

    public function testExtensionProviderRegistersAMediaEmbed(): void
    {
        $embed = self::embed();
        $want = "<p>Watch:</p>\n<iframe src=\"https://youtu.be/abc\"></iframe>";
        $this->assertSame($want, Carve2::render(self::EMBED, ['extensions' => [$embed]]));
        $tree = Carve2::parse(self::EMBED, ['extensions' => [$embed]]);
        $this->assertSame($want, Carve2::render($tree, ['extensions' => [$embed]]));
        $decoded = Carve2::decodeTree(Carve2::encodeTree($tree), ['extensions' => [$embed]]);
        $this->assertSame($want, Carve2::render($decoded, ['extensions' => [$embed]]));
        // Without the renderer the fallback, the core code block, is written.
        $this->assertStringContainsString('<code class="language-embed">', Carve2::render($tree));
        $this->assertSame(self::EMBED, Carve2::write($tree, null, ['extensions' => [$embed]]));
        $this->assertSame(self::EMBED, Carve2::format(self::EMBED, ['extensions' => [$embed]]));
        $this->assertSame([], Carve2::lint(self::EMBED, ['extensions' => [$embed]]));
        $this->assertStringContainsString('<input type="radio"', Carve2::render(
            ":::: tabs\n::: tab \"A\"\nx\n:::\n::::\n",
            ['extensions' => Tabs::extensions(true)],
        ));
    }

    public function testWriterRegistry(): void
    {
        $registry = Carve2::writers();
        $this->assertSame(['html', 'carve'], $registry->targets());
        $tree = Carve2::parse("Some *x*\n");
        $this->assertSame('<p>Some <strong>x</strong></p>', Carve2::writeTo('html', $tree));
        $this->assertSame("Some *x*\n", Carve2::writeTo('carve', $tree));

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
        $this->assertSame(['html', 'carve', 'text'], $registry->targets());
        $this->assertTrue($registry->has('text'));
        $this->assertSame('DOCUMENT:1', Carve2::writeTo('text', $tree, ['upper' => true], $registry));
        $this->assertSame('document:1', (new WriterRegistry([$stub]))->write('text', $tree));
        $this->assertFalse(Carve2::writers()->has('text'));

        $this->expectException(OptionError::class);
        Carve2::writeTo('markdown', $tree);
    }

    /**
     * @return array<string, array{\Closure(): mixed, class-string<\Throwable>}>
     */
    public static function refusals(): array
    {
        return [
            'unknown option' => [static fn () => Carve2::parse('a', ['nope' => true]), OptionError::class],
            'render option on parse' => [static fn () => Carve2::parse('a', ['sections' => false]), OptionError::class],
            'bad boolean' => [static fn () => Carve2::render('a', ['sections' => 'no']), OptionError::class],
            'bad enum' => [static fn () => Carve2::render('a', ['profile' => 'huge']), OptionError::class],
            'bad extensions' => [static fn () => Carve2::render('a', ['extensions' => ['x']]), OptionError::class],
            'bad includes' => [static fn () => Carve2::render('a', ['includes' => 'dir']), OptionError::class],
            'relative root' => [static fn () => FileResolver::forRoot('rel'), OptionError::class],
            'lint rule' => [static fn () => Carve2::lint('a', ['rules' => ['nope' => 'on']]), OptionError::class],
            'import format' => [static fn () => Carve2::importDocument('<p>a</p>', ['from' => 'markdown']), OptionError::class],
            'decode option' => [static fn () => Carve2::decodeTree('{}', ['sections' => true]), OptionError::class],
            'profile length' => [static fn () => Carve2::render(str_repeat('a', 100001), ['profile' => 'comment']), InputError::class],
            'strict losses' => [static fn () => Carve2::toHtml('{{ a.crv }}', ['strictLosses' => true]), RenderError::class],
            'not JSON' => [static fn () => Carve2::decodeTree('{'), TreeError::class],
            'bad tree' => [static fn () => Carve2::render(['type' => 'nope']), TreeError::class],
            'bad layout' => [static fn () => Carve2::validateLayout('{'), LayoutError::class],
            'unspellable' => [
                static fn () => Carve2::write([
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
