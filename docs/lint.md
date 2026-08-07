# Linting

`MarkdownHabitLinter` reports constructs that parse cleanly but almost certainly
do not mean what the author intended. Every finding is a `LintWarning` carrying
`line`, `column`, `rule`, `message`, `start` and `end`, mirroring the carve-js
shape so the two engines report the same finding in the same terms. Offsets are
byte offsets into the source you passed.

```php
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;

$warnings = (new MarkdownHabitLinter())->lint($source);
```

```sh
carve lint doc.crv
```

`carve lint` exits non-zero when anything is reported.

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
