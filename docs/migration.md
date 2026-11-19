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
| `$converter->convertWithReport($source, $strict, $max)` | `Carve::renderWithDiagnostics($source, ['strictLosses' => $strict])`; cap the list yourself |
| `$converter->parse($source)` | `Carve::parse($source, $options)` |
| `$converter->render($document)` | `Carve::render($tree, $options)` |
| `$converter->renderWithReport($document, ...)` | `Carve::renderWithDiagnostics($tree, $options)` |
| `$converter->parseWithSourceLayout($source)` | `Carve::parseWithLayout($source)` |
| `$converter->convertFile($path)`, `parseFile($path)` | gone: read the file, pass `includes` with `FileResolver::forRoot(dirname($path))` |
| `$converter->setProfile($profile)`, `getProfile()` | option `profile`: `full`, `article`, `comment`, `minimal` |
| `$converter->setSafeMode(...)`, `SafeMode` | option `profile` plus `urlPolicy`; raw HTML is off unless `rawHtml` |
| `$converter->setRenderMode($mode)`, `getRenderMode()` | option `mode`: `interactive` or `static` |
| `SmartTypographyMode`, `SmartQuotesExtension` | option `smartTypography` (on by default) |
| `$converter->getWarnings()`, `hasWarnings()`, `clearWarnings()` | `parseWithDiagnostics()->diagnostics`, `renderWithDiagnostics()->diagnostics` |
| `getProfileViolations()`, `hasProfileViolations()` | `renderWithDiagnostics()`, the entries of channel `render-loss` |
| `$converter->setRenderers([...])` | gone: a renderer belongs to its `ExtensionDefinition`; option `renderers` is reserved |
| `$converter->on($event, $listener)`, `RenderEvent` | gone: no render events; use an extension renderer or walk the tree |
| `$converter->addOutputTransformer($fn)` | gone: post-process the HTML string |
| `$converter->transform($document, ...$transformers)` | gone: claim nodes with an extension transform, or pass `Extension\DocumentTransform`s to `Carve::renderWithDocumentTransforms()` |
| `tryRenderHtmlStreaming()` | gone |
| `getRenderer()`, `getHtmlRenderer()`, `getParser()`, `getHeadingIdTracker()` | gone: internal in 0.2 |
| `createEditorSession()`, `parseWithNodeIdentity()`, `parseWithProvenance()`, `supportsIncrementalParagraphReuse()` | gone: not part of the 0.2 library contract |

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
| `SoftBreakMode` | gone |

The `roundtrip` option of the 0.1 Markdown writer is gone: 0.2 has no Markdown carrier format. The target `text` is
named `plain`. `docs/README.md` lists the losses the exports report.

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

## Import

| 0.1 | 0.2 |
|---|---|
| `(new HtmlToCarve())->convert($html)` | `Carve::importDocument($html)->source` |
| `convertWithReport($html)`, `convertWithFidelityReport($html)` | `Carve::importDocument($html)->diagnostics` |
| `convertToAst($html)` | `Carve::importDocument($html)->document` |
| `MarkdownToCarve`, `DjotToCarve`, `BbcodeToCarve` | gone from 0.2: not in the specification (C2-T-003); keep them on 0.1 |
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
| `InlineExtension` nodes | inline extensions; the PHP renderer writes their fallback for now |
| `TabsExtension` | `Extension\Tabs::extensions(true)` |
| `TableOfContentsExtension`, `WikilinksExtension`, `HeadingNumbersExtension`, `FrontmatterExtension`, `ExternalLinksExtension`, `SpoilerExtension`, `DetailsExtension`, `AutolinkExtension`, `MentionsExtension`, `CitationsExtension`, `AdmonitionExtension`, `ListTableExtension`, `SemanticSpanExtension`, `HeadingLevelShiftExtension`, and the other 0.1 extensions | not ported; several are core syntax in 0.2 (admonitions, mentions, citations, autolinks), the rest wait for the extension catalog |
| `HeadingPermalinksExtension`, `LowercaseHeadingIdsExtension`, `AsciiHeadingIdsExtension` | option `headingIds` for the id forms; permalinks are gone |

## Errors

| 0.1 | 0.2 |
|---|---|
| `ParseException`, `ParseWarning` | parse problems are diagnostics; bad options throw `OptionError` |
| `RenderLossException` | `RenderError` under `strictLosses` |
| `ProfileViolationException`, `ProfileViolation` | `render-loss` diagnostics, or `InputError` for a source over the profile limit |
| catching every engine error | `catch (\MarkupCarve\Carve\CarveException $e)` |
