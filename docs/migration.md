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
| `Renderer\HtmlRenderer` | `Carve::render()`; the HTML renderer is internal in 0.2 |
| `Renderer\CarveRenderer` | `Carve::format()` for source, `Carve::write()` for a tree |
| `Filter\ProfileFilter` | option `profile` |
| `Transform\TransformerInterface`, `RenderAwareTransformerInterface` | `Extension\DocumentTransform` |

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
| `$expander->getSuppressedWarnings()` | `suppressedDiagnostics` on the result of `Carve::parseWithDiagnostics()`: the include failures past the cap of 100 (C2-T-024); on `renderWithDiagnostics()` it also counts capped render losses |
| `$converter->toHtmlWithIncludes(...)`, `renderIncluded(...)` (host helpers) | `Carve::render($source, ['includes' => $resolver])` |
| `IncludeExpander::includeSitesForPosition()` | gone: a node from an included file carries `pos.file` |
| `IncludeExpander::selectIncludeRegion($source, $name, $mode)` | `Carve::selectIncludeRegion($source, $name, $mode)`, same arguments and result |

## Lint

| 0.1 | 0.2 |
|---|---|
| `(new DocumentLinter())->lint($source, $options)` | `Carve::lint($source, ['rules' => [...], 'platforms' => [...]])` |
| `(new IncludeLinter())->lint($source, $options, $resolver, $path)` | `Carve::lint($source, ['includes' => ['resolver' => $resolver, 'file' => $path]])` |
| `ReferenceLinter` | rules of `Carve::lint()` |
| `LintWarning`, `SourceOffsets` | `Diagnostics\Diagnostic` with byte offsets and a tree path |

To find text that 0.1 or Djot read differently (a bare `_x_` pair, a 0.1 cell alignment prefix, a `---` break, a code span padded with a space on each side, whose spaces 0.2 keeps), lint with `['upgrade' => true]` (`carve lint --upgrade`). These notices also
run for a document whose line-1 frontmatter declares `carve: 0.1` and stay off for a 0.2 document. `carve fix` keeps the same
gate: it rewrites them only under `--upgrade`, in such a document, or when `--rules` turns them on. A padded code span
(`code-span-padding-kept`) has no fix: often no 0.2 spelling keeps its 0.1 value, so check its spaces by hand.

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
| `Ast\AstCodec` | the same calls: `Carve::encodeTree()`, `Carve::encodeEnvelope()`, `Carve::decodeTree()` |
| `Ast\AstPatch` | `Carve::diffTree($a, $b)` and `Carve::applyPatch($tree, $patch)`; a bad patch throws `Json\PatchError` |
| `Exception\AstDecodeException`, `Exception\AstEnvelope*Exception` | `Json\TreeError` from `Carve::decodeTree()`, with `reason` and a JSON `pointer` |

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
| `DefaultAttributesExtension`, `TabNormalizeExtension` | `Extension\Transforms::defaultAttributes()`, `::tabNormalize()` |
| `InlineFootnotesExtension` (`[note]{.fn}`) | core syntax: write `^[note]` (C2-I-089); it shares the numbering of labeled footnotes |
| `InlineFootnotesToParenthesesTransform` | `Extension\Transforms::inlineFootnotesToParentheses()` |
| `InlineExtension` nodes | inline extensions; a registered renderer writes them, else their fallback |
| `TabsExtension` | `Extension\Tabs::extensions(true)` |
| `SvgSanitizer::sanitize($svg, $options)` | the same call: `Extension\SvgSanitizer::sanitize()` with `SvgSanitizeOptions` (adds `urlPolicy`); the img fence sanitizes on its own |
| `PlusBulletExtension` | dropped: in 0.2 a line that starts with `+` is text unless it is a table continuation row (C2-B-059, C2-B-175). Write `-` or `*`; `carve lint --upgrade` reports each `+` bullet (`djot-plus-bullet`) and `carve fix --upgrade` rewrites it where `-` opens a list; a `+` line directly after paragraph text stays as it is |
| `AdmonitionExtension(icons: ...)` | dropped: the core admonition renders `<aside class="admonition note">` (C2-H-154), so a stylesheet adds the icon, for example with `.admonition.note::before` |
| `FrontmatterExtension`, `MentionsExtension`, `AdmonitionExtension`, and the other 0.1 extensions | not ported; several are core syntax in 0.2 (admonitions, mentions, citations, autolinks), the rest wait for the extension catalog |
| `LowercaseHeadingIdsExtension`, `AsciiHeadingIdsExtension` | option `headingIds` for the id forms |

## Errors

| 0.1 | 0.2 |
|---|---|
| `ParseException`, `ParseWarning` | parse problems are diagnostics; bad options throw `OptionError` |
| `RenderLossException` | `RenderError` under `strictLosses` |
| `ProfileViolationException`, `ProfileViolation` | `render-loss` diagnostics, or `InputError` for a source over the profile limit |
| catching every engine error | `catch (\MarkupCarve\Carve\CarveException $e)` |

## Command line

The program is still `vendor/bin/carve`. The 0.1 flags were global and selected the output with a switch; 0.2 has
commands, and each command takes its own flags. Run `carve --help` for the full list.

`-w` changed meaning. In 0.1.18, `-w` on the render path was `--warnings` (print warnings to standard error). In 0.2,
`-w` is `--write` on `fmt`, `lint` and `fix`, which rewrites the files in place, and `render -w` is a usage error.
A script that called `carve -w doc.crv` to see warnings now gets exit `2`; use `carve diagnostics doc.crv` instead.
`fmt -w` writes the file in both versions.

| 0.1 | 0.2 |
|---|---|
| `-h`, `-V` | `--help`, `--version`; the short forms are gone |
| `--html` | `carve render` (the default command) |
| `--markdown`, `--md` | `carve render --to markdown` |
| `--plain`, `--plain-text` | `carve render --to plain` |
| `--ansi` | `carve render --to ansi` |
| `--carve` | `carve fmt` |
| `--json`, `--ast` | `carve ast` (also `--tree`) |
| `--from-json` | `--from-ast` (on `render`, `ast` and `fmt`) |
| `-w`, `--warnings` | `render` prints its render losses on standard error without a flag; `carve diagnostics FILE` lists the parse diagnostics |
| `--strict` | `--strict` stays: exit `1` when the render reports a diagnostic; `--strict-losses` also withholds the output on a loss |
| `-s`, `--safe` | `--profile` (see `setSafeMode()` above) with `--no-includes`; raw HTML is off unless `--raw-html` |
| `--static` | `--mode static` |
| `--smart-typography glyph\|source\|quotes-source` | the switch `--smart-typography` / `--no-smart-typography` |
| `--report-conversion-diagnostics FILE`, `--max-conversion-diagnostics N` | `--report-losses FILE` and `--max-render-losses N`, which also cover `render --to markdown\|plain\|ansi` |
| `--stamp-info`, `--stamp-check`, `fmt --stamp`, `fmt --stamp-block` | gone: 0.2 writes no provenance stamp |
| `fmt --migrate` | gone |
| `lint --platform github` | `lint --platforms '["github"]'` |
| `lint --extension NAME` | `lint --extensions '["NAME"]'`, names from the [catalog](extensions.md) |
| `carve migrate --from FORMAT FILE` | `carve import --from markdown\|gfm\|djot\|html FILE` |
| `carve migrate --report FILE`, `--check-loss`, `--mode`, `--djot-php` | gone: `import` prints each diagnostic as `carve[CODE]:` on standard error; `--strict-losses` fails the run on a loss |
| `carve merge base ours theirs` | gone |
| `carve trace FILE` | gone |

`carve merge`, `carve trace` and `carve migrate` are not commands in 0.2: the word is read as a file name and the run
fails with `input-unreadable`.

New in 0.2: the commands `ast`, `diagnostics`, `fix`, `flatten`, `migrate-ast` and `import`, `format` as an alias of
`fmt`, and `--no-NAME` for every switch. `fmt` adds `--diff`, `--normalize` and `--from-ast`; `lint` and `fix` add
`--rules`, `--platforms`, `--upgrade` and `--ignore`; `render` adds `--to`, `--profile`, `--url-policy`,
`--heading-ids`, `--heading-shift`, `--soft-break`, `--sections`, `--source-lines`, `--labels`, `--social` and
`--symbols`; `render`, `ast`, `lint` and `fmt` take `--extensions` and `--extension-options`. The
[README](../README.md#command-line) describes them.
