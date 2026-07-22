# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Inline literal** via the `` !`…` `` prefix (#378): a `!` immediately before a
  verbatim backtick span renders its content as escaped prose with no `<code>`
  wrapper, so notation that collides with the bare emphasis delimiters (phonemic
  `/kaet/`, glob patterns, paths) needs no per-character escaping. Mirrors the
  `$`-math prefix; a trailing `{…}` is the ordinary attribute block.
- Add an HTML `symbols` render map for trusted `:name:` replacements and wrap
  attributed symbols in `<span>` while leaving unmapped symbols literal.

### Fixed

- **Never pad all-space verbatim content in `carve fmt`.** A verbatim span whose
  content is entirely spaces was padded by the serializer even though the parser
  leaves it unstripped, so every fmt pass grew the span by two spaces
  (`` ` ` `` → `` `   ` `` → `` `     ` ``) and broke both formatter guarantees,
  `toHtml(fmt(x)) === toHtml(x)` and `fmt(fmt(x)) === fmt(x)`. Math was worse: it
  took no strip at all while still being padded, so `` $` x ` `` grew on every
  pass. Math now takes the same single-space strip as a code span (carve-js and
  carve-rs parity), the serializer pads exactly where the parser strips, and the
  code span, math and inline literal scanners share one `stripVerbatimPadding`
  helper so they cannot drift apart.
- Align symbol parsing with the pinned carve#261 spec: symbol names now start
  with an alphanumeric character, allow `+` after the first character, and only
  open at the start of text or after a non-word character. No AST rename was
  needed because carve-php already used `Symbol`.

## [0.1.2] - 2026-07-12

### Security

- Gate two ungated raw-HTML paths in the `HtmlToCarve` reverse converter that
  let untrusted HTML emit live `{=html}` passthrough (XSS); honored only with
  `trustedRoundTrip` (#293)
- Neutralize raw-HTML blocks minted from foreign code fences (XSS) (#298)
- Harden `::: toc` / `::: footnotes` placement: per-render output budget
  (amplification bound), Trojan-Source bidi controls stripped from TOC link
  text, renderer state protected against exceptions (#292)
- Linear nested `[quote]` parsing in `BbcodeToCarve` (was O(n^2) DoS) (#302)

### Added

- Opt-in `collapsible` option on `TableOfContentsExtension` (#297)

### Fixed

- Cross-implementation parity with carve-js / carve-rs (byte-verified):
  bare `{}` stays literal, class dedup, literal backslash in URLs (#294);
  math/table/block-attr/fmt divergences (#301); unclosed code span and raw
  image alt data loss (#299); separator-shaped rows never promoted to table
  headers (#317); dropped raw-format span keeps its leading space (#316);
  block images in blockquotes/lists (#319, #320); unresolved reference image
  formats verbatim (#321); permalink wrapper + wikilink attribute order (#312);
  empty details/spoiler body keeps a blank line (#311); `{% %}` is literal
  text (#313)
- Forward footnote reference inside a footnote body resolves (#300)
- TOC entry text trims the space left by an excluded section number (#305);
  trailing newline after injected `::: toc` nav (#310); genuine trailing empty
  cells in non-HTML tables (#308)
- `:index[term]` marker no longer feeds the heading slug (#306); glossary div
  wrapper kept on non-definition-list bodies (#307)
- `DjotToCarve` no longer corrupts footnote labels and pre-braced forms

## [0.1.1] - 2026-07-04

### Added

- `::: toc` and `::: footnotes` placement directives, including
  container-nested headings in `::: toc` placement (#288, #289)

### Fixed

- Extension-generated ids deduplicate against the document id namespace (#287)
- Heading auto-id deduplication skips reserved suffix candidates (#286)

## [0.1.0] - 2026-07-02

Initial release of **carve-php**, a PHP parser and renderer for the
[Carve](https://github.com/markup-carve/carve) markup language. Install via
Composer: `composer require markup-carve/carve-php`.

### Core parsing and rendering

- Block and inline parser producing a typed AST via `CarveConverter`
- Full Tier-1 feature set: headings (H1-H6), paragraphs, emphasis (`/italic/`,
  `*bold*`, `_underline_`, `~strikethrough~`, `^super^`, `,sub,`, `=highlight=`,
  `/*bold-italic*/`), blockquotes with attribution captions, unordered and ordered
  lists, task lists, tables (with colspan/rowspan), inline code and fenced code
  blocks, images (inline and block with captions), horizontal rules, hard breaks,
  YAML frontmatter, admonitions (`::: note`/`tip`/`warning`/`danger`), abbreviations
  (`*[ABBR]:`), mentions (`@user`), hashtags (`#tag`), display and inline math
  (`$$`/`` $` ``), inline extensions (`:type[...]`), attribute blocks (`{#id .class
  key=val}`), raw HTML passthrough (`=html`), comment lines (`%%`), and reference
  links/images
- Inline footnotes (`^[...]`) and block footnote definitions
- Editorial / critic markup (`{+ +}` insert, `{- -}` delete,
  `{~ old~>new ~}` substitute, `{= =}` highlight, `{# #}` comment)
- Smart typography: curly quotes, em/en dashes, ellipsis
- Reference definitions collected inside list items and containers
- HTML renderer via `(new CarveConverter())->convert()`
- Markdown renderer (`CarveConverter::markdown()->convert()`), plain-text
  renderer (`CarveConverter::plainText()->convert()`), ANSI-colored renderer
  (`CarveConverter::ansi()->convert()`)
- Static render mode (`setRenderMode(RenderMode::STATIC)`) for self-contained
  HTML; interactive constructs degrade gracefully
- Profiles API for named capability sets

### Extension API

- Parse-stage inline and block matchers (`addInlineMatcher`, `addBlockMatcher`,
  `addInlinePattern`, `addBlockPattern`) with priority and trigger-char fast path
- `MatcherContext` exposes reference/footnote/abbreviation tables and recursive
  parse helpers (`parseInlines`, `parseBlocks`)
- Render hooks and `before_render` / `after_parse` AST transforms
- `PlusBulletExtension` and other bundled extensions documented in
  `docs/extensions.md`

### Tier-2 opt-in extensions

- `mathBlock` - fenced ` ```math ` block rendered as `<div class="math display">`
  with author-attribute passthrough (core `$$` display math is always-on Tier-1)
- `citations` - `[@key]` reference citations with typed locators, explicit
  suffixes, and integral/group-level markers (§22)
- `codeCallouts` - annotated callout markers inside fenced code blocks

### Tier-3 opt-in extensions

- citations `bibliography` option - supplying a CSL-JSON pool renders a
  cite-ordered `<ol>` with back-links (a citations capability, not a standalone
  extension)
- `glossary` - `::: glossary` blocks with `:term[word]` inline markers linking to
  `gloss-{slug}` anchors
- `index` - `:index[term]` invisible span markers with a sorted `::: index` block
  collecting back-links
- `headingNumbers` - opt-in section auto-numbering and numbered `</#id>`
  cross-references via `<span class="section-number">`
- `colorSwatch` - `:color[value]` inline color preview chip; CSS named-color
  validation; configurable position, shape, tint; auto-contrast label variant;
  documented in the static-render demo
- `spoiler` - `:spoiler[text]` inline and `::: spoiler` block as native
  `<details class="spoiler">`
- `details` - `::: details "Title"` as HTML5 `<details>/<summary>`
- `fencedRender` - generic client-render factory (Mermaid preset included) with
  text and json content modes
- `listTable` - `::: list-table` nested-list-to-table with header-rows/cols and
  span markers (`^`/`<`)
- `tableOfContents`, `headingPermalinks`, `autolink`, `externalLinks`,
  `wikilinks`, `tabNormalize` - standard document-enhancement extensions

### Converters

- `HtmlToCarve` - round-trip converter from HTML back to Carve source; gated
  behind `trustedRoundTrip` (disabled by default) to prevent `data-djot-src`
  XSS when handling untrusted HTML
- `DjotToCarve`, `MarkdownToCarve`, `BbcodeToCarve` - migration converters with
  output-byte budget guards against DoS amplification

### CLI

- `bin/carve` binary reading Carve from a file or stdin
- `--html` (default) / `--markdown` (`--md`) / `--plain` / `--ansi` format
  selection
- `-o FILE` output to file; `--warnings` / `--strict` parse-warning reporting;
  `--xhtml` and `--safe` for HTML-output tuning
- `carve fmt` - canonical formatter (semantic-preserving, `-w` in-place,
  `--check` CI gate)

### Security (always-on, §25-§26)

- URL scheme denylist covering `javascript:`, `data:`, `vbscript:`, and OS
  protocol-handler schemes
- Dangerous attribute stripping (`on*`, `srcdoc`, `formaction`) on all elements
- Linear attribute-value regex replacing PCRE JIT path that could silently drop
  attributes on long values
- CSS `expression()` and `url()` neutralization in style attributes
- Trojan-Source hardening: NFC normalization of heading/footnote ids; bidi and
  zero-width Unicode control characters stripped from text and code content (§26)
- Uniform nesting depth cap of 200
- `HtmlToCarve` `data-djot-src` XSS closed (P0); `trustedRoundTrip` default-off
- Output-byte budgets on all reverse converters against amplification DoS

[Unreleased]: https://github.com/markup-carve/carve-php/compare/0.1.0...HEAD
[0.1.0]: https://github.com/markup-carve/carve-php/releases/tag/0.1.0
