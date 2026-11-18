# Carve 0.2 for PHP: library guide

The 0.2 engine ships in the same Composer package as 0.1 (`markup-carve/carve-php`), under the namespace
`MarkupCarve\Carve2`. Its one entry point is the static class `MarkupCarve\Carve2\Carve2`; every class it names in a
signature is listed in the [API reference](api.md). Everything else under `MarkupCarve\Carve2` is internal. Hosts
moving from 0.1 start with the [migration guide](migration.md).

PHP 8.2 or later. HTML import needs `ext-dom`.

Each example below runs in the test suite (`v2/tests/PublicApi/DocsExamplesTest.php`).

## Render and parse

`render()` takes Carve source or a tree. Options are one array, validated against the option table of the
specification; an unknown name or a bad value throws `OptionError`.

```php
use MarkupCarve\Carve2\Carve2;

$html = Carve2::render("# Title\n\nSome *strong* text.\n", ['sections' => false]);
assert($html === "<h1 id=\"Title\">Title</h1>\n<p>Some <strong>strong</strong> text.</p>");

$tree = Carve2::parse("# Title\n");
assert($tree->type === 'document');
assert(Carve2::render($tree, ['sections' => false]) === '<h1 id="Title">Title</h1>');
```

## Diagnostics

The `...WithDiagnostics` calls return the result and a list of `Diagnostic` entries (`code`, `severity`, `channel`,
`message`, `path`, byte offsets, `file`).

```php
use MarkupCarve\Carve2\Carve2;

$result = Carve2::renderWithDiagnostics('{{ part.crv }}');
assert($result->codes() === ['include-no-resolver', 'include-unresolved']);

$findings = Carve2::lint("{#a}\nx\n\n{#a}\ny\n");
assert($findings[0]->code === 'duplicate-id');
```

## Errors

Every error a call throws implements `MarkupCarve\Carve2\CarveException`: `OptionError`, `InputError`,
`RenderError`, `Writer\CarveWriteError`, `Json\TreeError`, `Layout\LayoutError` and `Import\ImportInputError`.

```php
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\CarveException;
use MarkupCarve\Carve2\OptionError;

try {
    Carve2::render('a', ['profile' => 'huge']);
} catch (CarveException $e) {
    assert($e instanceof OptionError);
}
```

## Format, write and import

```php
use MarkupCarve\Carve2\Carve2;

assert(Carve2::format("a  \nb\n") === "a\nb\n");
assert(Carve2::write(Carve2::parse("Some *x*\n")) === "Some *x*\n");
assert(Carve2::importDocument('<p>Hi <em>there</em></p>', ['from' => 'html'])->source === "Hi /there/\n");
```

## Tree JSON

`encodeTree()` gives the bare tree, `encodeEnvelope()` the envelope for storage, and `decodeTree()` reads either one
back, refusing a tree the reader checks refuse with `Json\TreeError`.

```php
use MarkupCarve\Carve2\Carve2;

$json = Carve2::encodeEnvelope(Carve2::parse("a\n"));
$tree = Carve2::decodeTree($json);
Carve2::validateTree($tree);
assert(Carve2::render($tree) === '<p>a</p>');
```

## Includes

Pass a resolver in the `includes` option: `Include\FileResolver::forRoot()` reads files under an absolute root
(C2-N-012), or implement `Include\IncludeResolver` to read from anywhere else. Without a resolver an include stays
unexpanded and is reported.

```php
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Include\IncludeResolver;

$resolver = new class implements IncludeResolver {
    public function resolve(array $request): array
    {
        return ['text' => "Included *text*.\n", 'id' => 'db:' . $request['path']];
    }
};
assert(Carve2::render('{{ intro.crv }}', ['includes' => $resolver]) === '<p>Included <strong>text</strong>.</p>');
assert(Carve2::includeDependencies('{{ intro.crv }}', ['includes' => $resolver])[0]['file'] === 'db:intro.crv');
```

## Output writers

`Carve2::writers()` returns a registry with the `html` and `carve` targets. A host adds its own `OutputWriter`. The
0.1 Markdown, plain text and ANSI writers are coming to 0.2 as the targets `markdown`, `text` and `ansi` on this same
interface, so code written against `writeTo()` will not change when they arrive.

```php
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Output\OutputWriter;

$registry = Carve2::writers()->register(new class implements OutputWriter {
    public function target(): string
    {
        return 'count';
    }

    public function write(Node $tree, array $options = []): string
    {
        return (string)count($tree->children ?? []);
    }
});
$tree = Carve2::parse("a\n\nb\n");
assert(Carve2::writeTo('html', $tree, [], $registry) === "<p>a</p>\n<p>b</p>");
assert(Carve2::writeTo('count', $tree, [], $registry) === '2');
```

## Extensions (provisional)

The extension host follows `extensions.md` of the specification and may change in 0.2.x. An extension is an
`ExtensionDefinition`: a qualified name, a version, its slots, a matcher (`div`, `fence` or `inline`) with a transform
that turns the claimed core node into an extension node, and an optional renderer, which is trusted host code. A
package ships its definitions through `ExtensionProvider` and the host passes it in the `extensions` option. The
same option goes to `parse`, `render`, `lint`, `format`, `write` and `decodeTree`.

```php
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Extension\Claim;
use MarkupCarve\Carve2\Extension\ExtensionDefinition;
use MarkupCarve\Carve2\Extension\ExtensionProvider;
use MarkupCarve\Carve2\Extension\RenderHost;
use MarkupCarve\Carve2\Node;

$embed = new class implements ExtensionProvider {
    public function definitions(): array
    {
        return [new ExtensionDefinition(
            'media.embed',
            '1',
            ['caption'],
            matcher: ['form' => 'fence', 'name' => 'embed'],
            transform: static function (Claim $claim): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => 'media.embed',
                    'version' => '1',
                    'slots' => ['caption' => []],
                    'props' => ['url' => trim($claim->source['body'] ?? '')],
                    'fallback' => [$claim->copyNode()],
                ];

                return $node;
            },
            renderer: static fn (Node $node, RenderHost $host): string
                => '<iframe src="' . RenderHost::escapeAttribute((string)($node->f['props']['url'] ?? '')) . '"></iframe>',
        )];
    }
};
$source = "```embed\nhttps://example.com/v/1\n```\n";
assert(Carve2::render($source, ['extensions' => [$embed]]) === '<iframe src="https://example.com/v/1"></iframe>');
assert(Carve2::format($source, ['extensions' => [$embed]]) === $source);
```

Not yet in the PHP engine: renderers for inline extensions (an inline extension renders its fallback), the
build-time renderer channel for `mode: static` and document transforms (rulings D3 and D4 of the 0.2 specification).
They will be added to `ExtensionDefinition` and the option map without changing the calls above.

## Command line

The package installs `carve2` (`vendor/bin/carve2`), the program of tooling.md beside the 0.1 `carve`. See
`v2/README.md` for its commands and flags.
