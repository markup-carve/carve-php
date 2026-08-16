# Linting

Three passes report constructs that parse cleanly but almost certainly do not
mean what the author intended. Every finding is a `LintWarning` carrying `line`,
`column`, `rule`, `message`, `start` and `end`, mirroring the carve-js shape so
the two engines report the same finding in the same terms. Offsets are byte
offsets into the source you passed.

`MarkdownHabitLinter` reads the **source**; `SemanticAttributeLinter` and
`RetiredSpellingLinter` parse and walk the **AST**. They are separate classes
because they answer separate questions, and none can be expressed in another's
terms.

```php
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use MarkupCarve\Carve\Lint\RetiredSpellingLinter;
use MarkupCarve\Carve\Lint\SemanticAttributeLinter;

$warnings = array_merge(
    (new MarkdownHabitLinter())->lint($source),
    (new SemanticAttributeLinter())->lint($source),
    (new RetiredSpellingLinter())->lint($source),
);
```

```sh
carve lint doc.crv
```

`carve lint` runs all three and exits non-zero when anything is reported.

## Markdown habits

On by default, because each one is a silent failure IN CARVE: the document is
valid, it just says something else. Carve diverges from Markdown deliberately -
`*x*` is strong and `_x_` is underline - so the doubled forms carry no meaning
and degrade to literal text.

| rule | what it catches |
|---|---|
| `markdown-strong-asterisks` | `**bold**`, which renders as literal asterisks |
| `markdown-strong-underscores` | `__bold__`, likewise |
| `markdown-strikethrough` | `~~gone~~` |

`*x*` and `_x_` are NOT reported: they are correct Carve, and warning on them
would punish authors writing the language properly. Verbatim spans are skipped,
so a code span holding `**not bold**` is code rather than a habit.

## Platform autolink rules

Off by default and selected per host. These two are the only rules that read the
document as text some **other** system will re-scan, rather than as Carve:

| rule | what it catches |
|---|---|
| `platform-mention-token` | an at-prefixed word a host re-linkifies into a user mention, which notifies whoever owns that handle |
| `platform-issue-reference` | a hash-number a host re-linkifies into an issue reference, which posts a backlink on whatever it resolves to |

```php
// neither rule can fire
$warnings = $linter->lint($source);

// both rules are enabled
$warnings = $linter->lint($source, ['platforms' => ['github']]);
```

```sh
carve lint --platform github doc.crv
```

The flag is repeatable, and `github` is the one platform the specification
defines - `MarkdownHabitLinter::knownPlatforms()` returns the names this build
accepts. An unknown name is **ignored** on the programmatic API and **refused**
on the command line. The asymmetry is deliberate: an API caller has a type
checker to catch a misspelling, while a misspelt flag that silently reported
nothing would be indistinguishable from a clean document.

They are two rules rather than one because the token shapes have different
false-positive profiles, and an author who cannot silence one without the other
silences both.

No render-time construct prevents this. The inline literal guarantees that the
*renderer* emits its content verbatim; it cannot bind a platform that consumes
the rendered HTML and re-parses the characters, because by then the marker is
gone and only the characters remain. The source is the only place the author's
intent still exists, which is what makes it a linter's job.

### What they read

Prose **and inline code spans** - a code span is not reliably safe, because some
host surfaces still linkify inside one. Two surfaces that look excluded are
deliberately checked, because both reach the published page: a captioned
listing's caption, and the body of a **referenced** footnote.

```
The @param annotation.                 platform-mention-token
Install @types/node now.               platform-mention-token
Write to user@example.com today.       no finding

See #42 now.                           platform-issue-reference
See (#123) now.                        platform-issue-reference
The #a1 selector.                      no finding
The #release-1.0 tag.                  no finding
```

### What they never read

Text a host never renders as prose, because a rule that reports a token nobody
can see is the over-eager rule this design exists to avoid:

- fenced code blocks, raw blocks and comments;
- frontmatter;
- link reference definitions and abbreviation definitions;
- a footnote definition that is never referenced, which is dropped from the
  output entirely;
- an inline link's destination, and the path, query and fragment of a bare URL,
  because a host linkifies those as a URL rather than as a separate mention or
  reference.

A link's **label** is read, so `[@param](https://example.com)` reports the
mention while the destination beside it does not.

## Semantic span attributes

On by default, and the first rules here that need a parse rather than a source
scan. PART 9 §9 and §10 give seven names meaning on an ordinary
`[content]{attrs}` span - `abbr`, `time` and `kbd` in core, and `samp`, `var`,
`cite` and `dfn` once the `SemanticSpanExtension` is registered. Two things fall
outside that scope with nothing marking the boundary.

| rule | what it catches |
|---|---|
| `semantic-attribute-value-ignored` | a value on a name that only selects its wrapper, so the value reaches no output |
| `semantic-attribute-outside-span` | a reserved name on anything other than a span, where it stays a raw attribute |

```php
use MarkupCarve\Carve\Lint\SemanticAttributeLinter;

$warnings = (new SemanticAttributeLinter())->lint($source);
```

Neither rule reports an engine defect: all three engines render these exactly as
the clause reads, and this package's output is unchanged. What they report is
that the clause's own scope loses something the author wrote.

### A discarded value

Only `abbr`, `dfn` and `time` carry an authored value into the output, as `title`
or `datetime`. On every other name the value only picks the element:

Input:

```
[Ctrl]{kbd="Control"}
```

Output:

```html
<p><kbd>Ctrl</kbd></p>
```

`Control` reaches no target on any renderer, so the rule says so.

### A reserved name off-span

§10 is scoped to an ordinary span, exactly. The same name on any other host
stays an ordinary attribute, which is what it has always rendered:

Input:

```
`c`{kbd}
```

Output:

```html
<p><code kbd="">c</code></p>
```

That is pre-existing and correct output - `kbd` was never a valid attribute of
`code`. The wart is that one spelling now means two things depending on what it
attaches to, and an author who learns `[x]{kbd}` from the docs and writes
`` `c`{kbd} `` gets an invalid attribute and no signal.

The message closes by quoting the attribute the render will contain, so it
describes the output rather than the input. That is the value **after** the
renderer's attribute sanitizer, cut at 120 codepoints when it is longer than
that, and escaped the way the renderer escapes it. An authored value is quoted
back:

Input:

```
`c`{kbd="keyboard"}
```

Output:

```html
<p><code kbd="keyboard">c</code></p>
```

and the message ends `renders as kbd="keyboard".` A value the sanitizer blanks
is reported as blank, because that is what the attribute holds:

Input:

```
`c`{kbd="javascript:alert(1)"}
```

Output:

```html
<p><code kbd="">c</code></p>
```

and the message ends `renders as kbd="".`

**`cite` on a block quote is never reported.** It is a URL attribute of
`blockquote` and `q` in HTML, so a quote carrying one is the author getting
exactly what they asked for:

Input:

```
{cite="https://example.org/dune"}
> Fear is the mind-killer.
```

Output:

```html
<blockquote cite="https://example.org/dune"><p>Fear is the mind-killer.</p></blockquote>
```

### Tell it which extensions you render with

Both rules are tier-aware. A name the render does **not** turn into an element is
an ordinary attribute everywhere, so its value reaches the output intact and
neither rule applies. `lint($source)` therefore reads a **core** render, where
only `abbr`, `time` and `kbd` are elements:

```php
// core: `cite` is an ordinary attribute, its value survives, nothing reported
$warnings = $linter->lint('[x]{cite="V"}');

// with the extension: `cite` selects <cite> and the value is dropped
$warnings = $linter->lint('[x]{cite="V"}', [
    'extensions' => [new SemanticSpanExtension()],
]);
```

Pass what you pass to the converter. `carve lint` reads a core render, because
the command line has no way to be told which extensions the document will be
published through.

## Composite figures

On by default. The five PART 9 §4c rules, each reporting a shape that parses
cleanly and renders less than it looks like it does. A tree-walking pass:
panel counts, nesting context and the demoted opener exist only in the parsed
document, so no source scan can see them.

| rule | what it catches |
|---|---|
| `figure-group-nested` | a `::: figure` opener inside a composite figure's body; nesting is rejected, so the inner fence stays a generic container |
| `figure-group-opener-metadata` | a `::: figure` opener carrying a quoted title or `[label]`; the figure production takes neither, so the fence stays a generic container with both preserved |
| `figure-group-panel-number` | a `#` placeholder in a PANEL caption; panels are not sequence units, so the placeholder stays a literal `#` - number the group caption instead |
| `figure-group-empty` | a `::: figure` group with no captionable panel |
| `figure-group-single-panel` | a `::: figure` group holding a single panel; a plain captioned figure renders the same content without the group wrapper |

```php
use MarkupCarve\Carve\Lint\FigureGroupLinter;

$warnings = (new FigureGroupLinter())->lint($source);
```

The panel predicate is the numbering resolver's own (`FigureGroup::isPanel()`),
so what the lint counts and what the resolver registers cannot drift apart, and
the message wording mirrors carve-js so a cross-engine report reads the same.

## Retired spellings

On by default. A retired spelling is a construct Carve has since redefined: the
document still parses, nothing errors, and the output is different. Only the
author knows which reading was meant, so this pass reports rather than rewrites.

| rule | what it catches |
|---|---|
| `table-cell-attribute-before-marker` | a table cell whose attribute block is immediately followed by `<`, `>` or `~`, which used to be the cell's alignment and is now content |

```php
use MarkupCarve\Carve\Lint\RetiredSpellingLinter;

$warnings = (new RetiredSpellingLinter())->lint($source);
```

PART 9 §5 T10 binds a cell's attribute block **after** its kind and alignment
markers, in every cell. That is what makes an attributed header cell spellable
at all - the block used to come first, where the only shape available was
`|{#x}=R|`, which reads as a data cell whose content starts with `=`.

The cost is that one released `data_cell` spelling reinterprets rather than
erroring:

Input:

```
|{#x}< content |
```

Output:

```html
<table>
  <tbody>
    <tr><td id="x">&lt; content</td></tr>
  </tbody>
</table>
```

The `<` is content now; under the retired order it left-aligned the cell. Both
readings parse, so the message names both spellings and the author picks.

**This is not a `fmt` rewrite, and must not become one.** Rewriting
`|{#x}< content |` to `|<{#x} content |` adds `text-align: left` and removes a
literal `<`, so a formatter doing it in the default path would break
`toHtml(fmt(x)) == toHtml(x)` on a document that renders correctly today.

A space in front of the sigil is unaffected: `|{#x} < content |` was content
under both orders, so nothing was reinterpreted. So is a block that already sits
after a marker - `|<{#x} content |` is the migration target, not the problem.

The pass walks the AST and then reads the source each cell came from, rather
than scanning lines for a row shape. A table row is a row wherever it stands -
in a block quote, in a list item, at any content column its container gives it -
and a line scan would have to reconstruct all of that to decide whether `|...|`
is a row at all. It would also report a fenced **example** of the retired
spelling as if it were a document, which is what every example on this page is.
