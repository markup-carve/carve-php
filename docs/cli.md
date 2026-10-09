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

## Exact-case reference migration

Exact-case lookup changes heading cross-references, numbered caption and equation
references, and collapsed references that fall back to heading text.
Link-definition labels, footnote labels and include fragment selectors were
already case-sensitive in the previous published engine. Whitespace normalization, NFC and default heading slug derivation
are unchanged. Case-distinct ids identify separate targets.

With the new engine, run `carve lint` before deploying the rendered output.
`carve fmt --migrate`
repairs unambiguous case-only cross-reference and link or image label misses,
including label mistakes that were already unresolved before this release.
Review the result: changing a collapsed label also changes its visible text or
image alternative text. Ambiguous matches and labels carrying inline markup
need manual review. Include selectors, glossary references and external
fragment links are outside this repair. Glossary ids now preserve case, so
update links to those ids separately. Ordinary `fmt` does not apply this repair.

## Import migration gate

`carve migrate --from html|markdown|djot|bbcode` can write the shared version 2
fidelity envelope with `--report FILE`, or to stderr with `--report -`. The
report classifies findings as `preserved`, `normalized`, `degraded`, or
`dropped`, with explicit confidence. `--check-loss` exits with status 1 when
the report contains degraded or dropped content.

Markdown, Djot, and BBCode verify a narrow literal-text subset: empty input or
Unicode letters and numbers separated by single ASCII spaces, with optional
trailing line endings. When the imported text matches and no known loss was
reported, `literal-text-verified` records preserved/exact evidence. All other
inputs retain the dropped/fallback `fidelity-unverified` warning.

Markdown reports `raw-span-whitespace-trimmed`, warning/degraded/exact, at the
source line of every raw span whose content would end a content line in
whitespace. CARVE-P2-025 drops a whitespace run at the end of every content
line, and a verbatim run crossing a line break is no exception, so this input:

```markdown
<a href="foo  
bar">
```

is written with its two spaces and read back without them. Degraded rather than
dropped, because the span and its text survive and the whitespace does not; the
loss is reported rather than respelled as a raw block, which would keep the
bytes at the cost of a different block structure
(markup-carve/carve#2804). Whitespace a raw span carries anywhere but a line
end is not reported, because Carve keeps it.

HTML reports its import
`mode` and `adapter`; its resource-limit exceptions are reported as command
errors rather than partial migration reports. Opaque raw HTML is `degraded` even
when its bytes survive, because the importer neither models nor can edit it.

 `--include-root DIR` sets the containment root for `{{ path }}` include
 directives. A file input already defaults to the document's own directory, so
 the flag is for widening that root or for enabling includes on stdin, which has
 no path context of its own. A relative `DIR` is expanded against the working
 directory here, in argument parsing; the resolver itself requires an absolute
 root. See [File inclusion](includes.md).

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
report a document's provenance marker (see below). `--carry-markers` applies to
`--markdown` only: it brackets every container Markdown drops with an HTML
comment holding its Carve opener, so `carve migrate --from markdown` returns the
container (see [`docs/markdown-output.md`](markdown-output.md)). `-o FILE` writes to a file; `-w`/`--warnings` and `--strict` report
parse warnings (exit 1 under `--strict`); `-x`/`--xhtml` and `-s`/`--safe` apply
to HTML output only. Run `bin/carve --help` for the full list.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
