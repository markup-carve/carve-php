# Command line

The package ships a `bin/carve` executable that reads Carve from a file or
stdin and writes the rendered output to stdout. HTML is the default; pass a
format flag for another output:

~~~ bash
bin/carve README.crv > README.html   # HTML (default)
bin/carve --markdown README.crv      # Markdown
bin/carve --plain README.crv         # plain text
bin/carve --ansi README.crv          # ANSI-colored terminal text
echo '# Hello' | bin/carve           # render from stdin
bin/carve merge base.crv ours.crv theirs.crv # structural three-way merge
~~~

## Import migration gate

`carve migrate --from html|markdown|djot|bbcode` can write the shared version 2
fidelity envelope with `--report FILE`, or to stderr with `--report -`. The
report classifies findings as `preserved`, `normalized`, `degraded`, or
`dropped`, with explicit confidence. `--check-loss` exits with status 1 when
the report contains degraded or dropped content.

Markdown, Djot, and BBCode currently fail closed as `dropped` with `fallback`
confidence because those importers do not yet produce construct-level evidence.
HTML reports its import `mode` and `adapter`; its resource-limit exceptions are
reported as command errors rather than partial migration reports.

 `--include-root DIR` sets the containment root for `{{ path }}` include
 directives. A file input already defaults to the document's own directory, so
 the flag is for widening that root or for enabling includes on stdin, which has
 no path context of its own. See [File inclusion](includes.md).

`AstMerge::merge()` exposes the same conservative merge to applications: it
combines independent field edits, insertions, deletions, and moves, and returns
explicit JSON-Pointer conflicts instead of choosing an ambiguous winner.
`AstPatch::create()` and `AstPatch::apply()` provide position-independent patch
replay. Position metadata is intentionally regenerated after serialization.

`--html` / `--markdown` (`--md`) / `--plain` (`--plain-text`) / `--ansi` select
the format. `--json` (`--ast`) emits the parsed AST instead of rendering it, and
`--from-json` reads an encoded AST instead of Carve source, so a tree can be
produced by one tool and rendered by another. The field names are the ones PART 12
of the spec pins, so a tree from another engine reads correctly - and one this
decoder cannot fully understand is rejected rather than silently decoded into the
wrong document. `--json` asks the parser to track source positions and publishes
them (PART 12 §4); the other formats do not, since tracking costs work on every
parse and only this one publishes the result.
See [`docs/ast-json.md`](ast-json.md). `--stamp-info` and `--stamp-check`
report a document's provenance marker (see below). `-o FILE` writes to a file; `-w`/`--warnings` and `--strict` report
parse warnings (exit 1 under `--strict`); `-x`/`--xhtml` and `-s`/`--safe` apply
to HTML output only. Run `bin/carve --help` for the full list.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
