# Include directives

File inclusion is an opt-in transform. Without a resolver, `{{ chapter.crv }}`
is ordinary text and renders literally - the core parser performs no file I/O
and never resolves a path. To expand includes, parse first, run
`IncludeExpander`, then render:

~~~ php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeExpander;

$converter = new CarveConverter();
$document = $converter->parse("Intro.\n\n{{ chapters/one.crv @shift:1 }}\n");

$expander = new IncludeExpander(
    resolver: new FilesystemIncludeResolver(__DIR__ . '/docs'),
    currentPath: 'index.crv',
    extensions: $converter->getExtensions(),
);

$html = $converter->render($converter->transform($document, $expander));
$warnings = $expander->getWarnings();
$dependencies = $expander->getDependencies();
~~~

## Extensions

A child is parsed as a document of its own, with the extensions passed as
`extensions`. Pass the parent converter's, or syntax an extension adds (a
`[[Page]]` wikilink, a citation) works in the parent and stays literal in the
child. Each one is cloned for the child's parse.

## The resolver contract

`resolve(string $path, IncludeContext $context): ResolvedInclude|string|null`
returns Carve source text, optionally with a canonical id, or throws on
failure. Supplying an id is recommended: it is what the cycle guard compares,
so without one `b.crv` and `./b.crv` are two files as far as the guard is
concerned and only the depth limit stops the recursion.

The transform recognizes `#section`, `@lines:N-M`, `@shift:N` and
`@shift:auto`. Automatic shifting places the included document's shallowest
heading one level below the nearest preceding heading in the include site's own
or enclosing block container. Failures leave the directive literal and add an
include warning.

## Reported dependencies

`getDependencies()` returns the de-duplicated include targets touched across
the whole recursive expansion, in document order, including missing, denied,
binary, cycle-broken and budget-blocked attempts. Each entry says whether it
resolved. A preview re-runs the expansion when any of them changes - including
the unresolved ones, since creating a missing file is exactly what makes the
include start working.

## Source positions across files

With position tracking on, a node an include pulled in keeps the coordinates of
its OWN file and names that file in `SourceSpan::$file`. A node from the
document being parsed has none. Without it an included span is ambiguous: a
child's first paragraph and the parent's first paragraph both report line 1.

## From the command line

`bin/carve` is a host like any other, so it supplies a resolver itself. A FILE
input expands includes with the containment root defaulting to the document's
own directory:

~~~ bash
bin/carve book/main.crv                  # root defaults to book/
bin/carve --include-root . book/main.crv # widen the root to the project
bin/carve --include-root ./book < main.crv
~~~

The root is never the process working directory, which is arbitrary with respect
to the document. Stdin has no path context and therefore no inferable root, so a
directive stays literal there unless `--include-root` names one.

`--carve` is excluded: that target writes the document back as Carve, and
inlining every child would hand back a different document than the author wrote.
`--from-json` is excluded too, since its input is a tree rather than source.

Include warnings print on stderr without `--warnings`. A refused directive
renders as the literal text it is, which reads exactly like prose somebody
typed, so silence would hide a real error behind something that looks normal.

### One self-contained file

`bin/carve flatten` writes the document back as Carve with every include
expanded in place - the deliberate opposite of `fmt`, which leaves directives
alone so formatting returns the author's document. Flattening is for handing the
document to something with no filesystem behind it: a web editor, a paste box, a
colleague.

~~~ bash
bin/carve flatten book/main.crv > one-file.crv
bin/carve flatten --include-root ./book < main.crv
~~~

Two things it changes beyond inlining, both reported on stderr: the output is
canonical Carve, so formatting is normalized rather than preserved, and
colliding explicit ids and footnote labels are renamed. The renames are written
into the source, so the flattened file renders exactly like the expanded
original.

## Security

**Resolver configuration is the security boundary.** Hosts must opt in, and the
resolver owns path containment.

`FilesystemIncludeResolver` canonicalizes targets with `realpath()`, rejects
absolute paths by default, rejects any target outside the configured root
(symlink escapes and `..` traversal alike), rejects URI schemes, and refuses
any target over `maxFileBytes` (4 MiB; pass `null` to lift the cap).

The root itself must be an ABSOLUTE path. A non-absolute spec is refused rather
than canonicalized, because every canonicalizer resolves one against the process
working directory - the one default the spec forbids - and `is_dir()` then
accepts the result. Blank, whitespace-only and relative specs fall out of that
one rule; a directory genuinely named with spaces stays reachable by its
absolute path. A host with no root leaves inclusion disabled and directives
literal.

The spec constrains the root, not what a front end computes, so `carve
--include-root .` keeps working: the CLI expands the flag against the working
directory in its own argument parsing, before it builds a resolver.

A refusal never reveals whether the target exists. Containment is decided on the
canonical candidate - the longest existing prefix canonicalized, the remainder
re-appended lexically - so a target outside the root is refused as an escape
whether or not it is on disk, and only targets whose canonical result is inside
the root can be reported as missing.

Inclusion is a source merge, not a privilege boundary: included content is
parsed under the same sanitization as any other content, so a child carrying a
raw-HTML block puts raw HTML in the parent's output. Render in safe mode if the
include root is writable by anyone less trusted than the page author.

### Limits

Four limits bound what one document may cost, all overridable:

| Limit | Default | Bounds |
|---|---|---|
| `depthLimit` | 16 | Nesting depth |
| `byteBudget` | `max(1 MiB, 8x input)` | Total expanded source |
| `resolverCallLimit` | 1000 | Resolver calls, so reads and lookups |
| `warningLimit` | 100 | Retained warnings |

The byte budget alone does not bound the pass's own work: a directive is
resolved before it can be refused, and a document may carry one directive per
dozen bytes. `resolverCallLimit` bounds that. Once either total is spent, the
remaining directives degrade to literal without being resolved at all.

`warningLimit` keeps one warning per distinct rule and counts the rest, which
`getSuppressedWarnings()` reports - a capped report is never a clean one.

For untrusted input, set `byteBudget` to an absolute value rather than leaving
the default, which scales with the input and so lets the input raise its own
ceiling.
