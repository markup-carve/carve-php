# Moving a PHP host from Carve 0.1 to 0.2

This maps every 0.1 call that the PHP integrations use today (Laravel, Symfony, CakePHP, Tempest, Shopware, wp-carve,
carve-php-media-embed, carve-php-chat, tempest-highlight-carve) to its 0.2 call. Source documents move with the
`upgrade` tool of the reference engine (`UPGRADING.md` of the specification); this page is about PHP code.

0.2 replaces 0.1 in the same package and namespace: `MarkupCarve\Carve` now holds the 0.2 engine, and every 0.1 class
listed below is gone. The command line stays `vendor/bin/carve`, with the 0.2 commands. A host moves all its call sites
together with the version bump.

The main change of shape: 0.1 built a configured `CarveConverter` object and called methods on it; 0.2 has static
calls on `MarkupCarve\Carve\Carve` that each take one validated options array. Keep the array where you kept the
converter.

## Converter and rendering

| 0.1 | 0.2 |
|---|---|
| `new CarveConverter(...)`, `CarveConverter::create(...)` | none; pass the settings as `$options` to each call |
| `$converter->convert($source)` | `Carve::render($source, $options)` |
| `$converter->convertWithReport($source, $strict, $max)` | `Carve::renderWithDiagnostics($source, ['strictLosses' => $strict, 'maxLosses' => $max])`; `suppressedDiagnostics` counts the entries past the cap |
| `$converter->convertWithReport(...)->toArray()` | `Carve::renderWithDiagnostics(...)->toArray()`; the array has `html`, `diagnostics`, and `suppressedDiagnostics` |
| `$converter->parse($source)` | `Carve::parse($source, $options)` |
| `$converter->render($document)` | `Carve::render($tree, $options)` |
| `$converter->renderWithReport($document, ...)` | `Carve::renderWithDiagnostics($tree, $options)` |
| `$converter->parseWithSourceLayout($source)` | `Carve::parseWithLayout($source)` |
| `$converter->convertFile($path)`, `parseFile($path)` | gone: read the file, pass `includes` with `FileResolver::forRoot(dirname($path))` |
| `$converter->setProfile($profile)`, `getProfile()` | option `profile`: `full`, `article`, `comment`, `minimal` |
| `new CarveConverter(xhtml: true)` | `Carve::render($source, ['xhtml' => true])`; the CLI keeps `-x` and `--xhtml` |
| `$converter->setSafeMode(...)`, `SafeMode` | option `profile` plus `urlPolicy`; raw HTML is off unless `rawHtml` |
| `$converter->setRenderMode($mode)`, `getRenderMode()` | option `mode`: `interactive` or `static` |
| `SmartTypographyMode` | option `smartTypography` (on by default); locale quotes through `Extension\SmartQuotes` |
| `$converter->getWarnings()`, `hasWarnings()`, `clearWarnings()` | `parseWithDiagnostics()->diagnostics`, `renderWithDiagnostics()->diagnostics` |
| `getProfileViolations()`, `hasProfileViolations()` | `renderWithDiagnostics()`, the entries of channel `render-loss` |
| `$converter->setRenderers([...])` | gone: a renderer belongs to its `ExtensionDefinition`; option `renderers` is reserved |
| `$converter->on($event, $listener)`, `RenderEvent` | gone: no render events; use an extension renderer or walk the tree |
| `$converter->addOutputTransformer($fn)` | gone: post-process the HTML string |
| `$converter->transform($document, ...$transformers)` | gone: a `documentTransform` on an `ExtensionDefinition`, or `Extension\DocumentTransform`s passed to `Carve::renderWithDocumentTransforms()` |
| `$converter->tryRenderHtmlStreaming($source, $sink)` | `Carve::tryRenderHtmlStreaming($source, $sink, $options)`; the sink receives the full HTML in one call |
| `getRenderer()`, `getHtmlRenderer()`, `getParser()`, `getHeadingIdTracker()` | gone: internal in 0.2 |
| `$converter->createEditorSession($source)` | `Carve::createEditorSession($source, $options)`; edit ranges remain UTF-8 byte offsets |
| `$converter->supportsIncrementalParagraphReuse()` | `Carve::supportsIncrementalParagraphReuse()` |
| `parseWithNodeIdentity()`, `parseWithProvenance()` | gone |

## Carve output

| 0.1 | 0.2 |
|---|---|
| `CarveConverter::toCarve($source, $alignTables)` | `Carve::format($source, ['alignTables' => $alignTables])` |
| `CarveConverter::carve()` with `->convert($source)` | `Carve::format($source)`, or `Carve::write($tree)` for a tree |
| `toCarvePatch()`, `stampCarve()` | gone |

## Other output formats

| 0.1 | 0.2 |
|---|---|
| `CarveConverter::markdown()`, `MarkdownRenderer` | `(new Export\MarkdownExporter())->export($tree)`, or `Carve::writeTo('markdown', $tree)` |
| `CarveConverter::plainText()`, `PlainTextRenderer` | `(new Export\PlainTextExporter())->export($tree)`, or `Carve::writeTo('plain', $tree)` |
| `CarveConverter::ansi()`, `AnsiRenderer` | `(new Export\AnsiExporter())->export($tree)`, or `Carve::writeTo('ansi', $tree)` |
| `RendererInterface`, `RenderTarget`, `RenderTargetInterface` | `Output\OutputWriter`, registered on `Carve::writers()` |
| `TableLayout::expand()` | `Carve::tableGrid($table)` |
| `StringUtil::findSafeCodeFence()` | `Carve::safeCodeFence($content)` |
| `SoftBreakMode` | option `softBreak` (`newline`, `space` or `br`) |

The `roundtrip` option of the 0.1 Markdown writer is gone: 0.2 has no Markdown carrier format. The target `text` is
named `plain`. `docs/README.md` lists the losses the exports report.

## ProseMirror bridge

The bridge keeps its class names and moves to the schema of the 0.2 Tiptap kit in carve-grammars
(`tiptap/tree.js`). Details in [prosemirror.md](prosemirror.md).

| 0.1 | 0.2 |
|---|---|
| `(new ProseMirrorRenderer())->renderJson($document)` | `(new ProseMirrorRenderer())->renderJson(Carve::parse($source))`, or `renderSource($source)` for the array |
| `(new ProseMirrorToCarve())->convert($array)`, `convertJson($json)` (a `Document`) | `convert($arrayOrJson)` for Carve source, `toTree($arrayOrJson)` for the tree |
| `droppedTypes()`, `degradedTypes()` | gone: nothing is dropped; a node the editor cannot hold travels as a `carveBlock` or `carveInline` atom |
| `droppedAttributes()`, `degradedAttributes()` | every tree field travels in the `carve` attribute; `ignoredAttributes()` lists the editor attributes the save did not read, such as `textAlign` or a link `target` |
| `destination-denied` in `droppedTypes()` | `blanked()`: the denied link and image destinations, `null` in the editor attribute and kept in `carve` |
| `ProseMirrorRenderer::register()`, `ProseMirrorToCarve::register()` | the `mapping` constructor option of both classes, one table for both directions ([prosemirror.md](prosemirror.md#application-nodes)); an unknown ProseMirror name is an error |
| `SchemaMap::carveTypeFor()` | `ProseMirrorToCarve::carveTypeFor()`, and `proseMirrorNames()` for the whole map |
| `withSourcePositions()`, `carvePos` | gone: positions never reach the editor document |
| `resources/prosemirror-schema-map.json`, `resources/prosemirror-wire-fixtures.json`, `scripts/check-schema-map.php` | the kit itself is the schema; `tests/fixtures/prosemirror/kit-wire.json` pins its output |
| 0.1 attributes (`id`, `class`, `carveKeyValues`, `carveTight`, `carveMarker`, ...) and nodes (`carveLinkRefDef`, `carveSymbol`, `carveFigure`, ...) | not read: load a stored 0.1 editor document with carve-php 0.1, write its Carve source, and load that source |

## Includes

| 0.1 | 0.2 |
|---|---|
| `new IncludeExpander($resolver, ...)`, `->transform($document)` | option `includes` on `parse`, `render`, `lint` |
| `IncludeResolverInterface::resolve($path, IncludeContext $context)` | `Include\IncludeResolver::resolve(array $request)`: `path`, `from`, `options`, `section`, `budget` |
| `ResolvedInclude` | the array `['text' => ..., 'id' => ...]`, or `['error' => 'not_found']` and the other causes |
| `new FilesystemIncludeResolver($root)` | `Include\FileResolver::forRoot($root)` (absolute root) |
| `$expander->getDependencies()` | `Carve::includeDependencies($tree)` |
| `$expander->getWarnings()` | the `include-*` entries of `parseWithDiagnostics()` |
| `$expander->getSuppressedWarnings()` | not exposed yet |
| `$converter->toHtmlWithIncludes(...)`, `renderIncluded(...)` (host helpers) | `Carve::render($source, ['includes' => $resolver])` |
| `IncludeExpander::includeSitesForPosition()` | gone: a node from an included file carries `pos.file` |

## Lint

| 0.1 | 0.2 |
|---|---|
| `(new DocumentLinter())->lint($source, $options)` | `Carve::lint($source, ['rules' => [...], 'platforms' => [...]])` |
| `(new IncludeLinter())->lint($source, $options, $resolver, $path)` | `Carve::lint($source, ['includes' => ['resolver' => $resolver, 'file' => $path]])` |
| `ReferenceLinter` | rules of `Carve::lint()` |
| `LintWarning`, `SourceOffsets` | `Diagnostics\Diagnostic` with byte offsets and a tree path |

To find text that 0.1 or Djot read differently (a bare `_x_` pair, a 0.1 cell alignment prefix, a `---` break, a code span padded with a space on each side, whose spaces 0.2 keeps), lint with `['upgrade' => true]` (`carve lint --upgrade`). These notices also
run for a document whose line-1 frontmatter declares `carve: 0.1` and stay off for a 0.2 document. `carve fix` keeps the same
gate: it rewrites them only under `--upgrade`, in such a document, or when `--rules` turns them on.

## Import

| 0.1 | 0.2 |
|---|---|
| `(new HtmlToCarve())->convert($html)` | `Carve::importDocument($html)->source` |
| `convertWithReport($html)`, `convertWithFidelityReport($html)` | `Carve::importDocument($html)->diagnostics` |
| `convertToAst($html)` | `Carve::importDocument($html)->document` |
| `(new MarkdownToCarve())->convert($md)` | `Carve::importDocument($md, ['from' => 'markdown'])->source` (`'gfm'` for GitHub Markdown) |
| `carve migrate --from markdown --directory SRC --output DST` | `carve import --from gfm --directory SRC --output DST`; writes converted `.crv` files, copies assets, checks links, and writes `migration-report.json` |
| `DjotToCarve::convert($djot)` | `Carve::importDocument($djot, ['from' => 'djot'])->source` |
| `BbcodeToCarve` | not in PHP 0.2; BBCode import is JavaScript only (`@markup-carve/carve/import`) |
| `MigrationResult`, `MigrationDiagnostic` | `ImportResult` and its diagnostics |

## Tree

| 0.1 | 0.2 |
|---|---|
| `Node\Document`, `Node\Block\*`, `Node\Inline\*`, `NodeType` | one `Node` class: `$node->type` is the schema type, other fields are in `$node->f` |
| exporting the AST | `Carve::encodeTree()`, `Carve::encodeEnvelope()`, `Carve::decodeTree()`, `Carve::validateTree()` |

## Extensions

The 0.2 extension host is provisional (see the [guide](README.md#extensions-provisional)).

| 0.1 | 0.2 |
|---|---|
| `ExtensionInterface::register(CarveConverter $converter)`, `addExtension()`, `addExtensions()` | an `Extension\ExtensionProvider` (or `ExtensionDefinition`s) in option `extensions` |
| `getExtensions()` | gone: the host keeps its list |
| `FencedRenderExtension`, `MermaidExtension` and the other diagram presets | `Extension\FencedRender::bundle()` and its presets (`mermaid()`, `presets()`), see [extensions.md](extensions.md) |
| `MathBlockExtension`, `CodeGroupExtension`, `CodeCalloutsExtension` | `Extension\MathBlock::bundle()`, `Extension\CodeGroup::bundle()`, `Extension\CodeCallouts::bundle()` |
| `DetailsExtension`, `SpoilerExtension`, `ListTableExtension`, `ColorSwatchExtension`, `SemanticSpanExtension` | `Extension\Details::bundle()`, `Extension\Spoiler::bundle()`, `Extension\ListTable::bundle()`, `Extension\ColorSwatch::bundle()`, `Extension\SemanticSpan::bundle()` |
| `WikilinksExtension` | `Extension\Wikilinks::bundle()` (the standard `:wiki` matcher with a link renderer) |
| `SmartQuotesExtension(locale: 'de')` | `Extension\SmartQuotes::bundle('de')` |
| `TableOfContentsExtension`, `HeadingPermalinksExtension`, `HeadingNumbersExtension`, `HeadingReferenceExtension`, `ExternalLinksExtension`, `LinkPolicy`, `AutolinkExtension` | `Extension\Navigation::tableOfContents()`, `::tocPlacement()`, `::headingPermalinks()`, `::headingNumbers()`, `::headingReference()`, `::externalLinks()`, `::linkPolicy()`, `::autolink()` |
| `HeadingLevelShiftExtension` | the render option `headingShift` (0 to 5) and `--heading-shift`; a level above 6 is no longer merged into 6 (C2-H-208) |
| `GlossaryExtension`, `IndexExtension`, `CitationsExtension(mode:, bibliography:)` | `Extension\Glossary::bundle()`, `Extension\IndexTerms::bundle()`, `Extension\Citations::bundle(mode:, bibliography:)` with their transforms |
| `DefaultAttributesExtension`, `TabNormalizeExtension`, `InlineFootnotesExtension` | `Extension\Transforms::defaultAttributes()`, `::tabNormalize()`, `::inlineFootnotesToParentheses()` |
| `InlineExtension` nodes | inline extensions; a registered renderer writes them, else their fallback |
| `TabsExtension` | `Extension\Tabs::extensions(true)` |
| `FrontmatterExtension`, `MentionsExtension`, `AdmonitionExtension`, and the other 0.1 extensions | not ported; several are core syntax in 0.2 (admonitions, mentions, citations, autolinks), the rest wait for the extension catalog |
| `LowercaseHeadingIdsExtension`, `AsciiHeadingIdsExtension` | option `headingIds` for the id forms |

## Errors

| 0.1 | 0.2 |
|---|---|
| `ParseException`, `ParseWarning` | parse problems are diagnostics; bad options throw `OptionError` |
| `RenderLossException` | `RenderError` under `strictLosses` |
| `ProfileViolationException`, `ProfileViolation` | `render-loss` diagnostics, or `InputError` for a source over the profile limit |
| catching every engine error | `catch (\MarkupCarve\Carve\CarveException $e)` |
