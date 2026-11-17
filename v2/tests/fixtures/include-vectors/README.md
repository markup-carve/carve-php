# Include vectors for 0.2

The 212 include vectors of 0.1 (`tests/include-conformance/vectors` on `main` of `markup-carve/carve`), ported to
the 0.2 Includes document (`spec/0.2/includes.md`). `scripts/port-include-vectors.ts` writes them;
`test/include-vectors.test.ts` runs them.

`manifest.json` holds the counts, the vectors a clause changed although the classification of the specification
session kept them (`reclassified`), and the dropped vectors with their reason. Each file in `vectors/` is one vector:

- `verdict`: `unchanged` (the 0.1 expectation holds) or `changed` (`note` says what changed, `clauses` where it comes
  from).
- `resolver`: `virtual` serves `files` under their names, each name its own id; `virtual-ids` strips a leading `./`
  first; `throws` throws `options.throws`; `none` gives no resolver; `filesystem` reads the file `tree` (a value
  `{"symlink": target}` is a symbolic link to `target`, relative to the tree) with the containment root `root` and the
  entry `entryPath`. A filesystem id is the canonical path relative to the root.
- `options`: `depth` and `bytes` lower the two limits; `file` is the id of the top-level document.
- `expected.html`: the render with default options. `<TMP>` stands for the directory the tree was written to.
- `expected.fmt`: the canonical source of the entry, which no resolver changes (C2-N-005).
- `expected.warnings`: the codes of the parse and the render-loss channel, as a multiset, each with the `file` of the
  node it is about when that is an included file.
- `expected.dependencies`: the include nodes in tree order, with repeats removed (C2-N-084): a read node by its `file`,
  an unread one by the place its path names from its including file, or by its path where that place is outside
  the root.
- `expected.positions`: the positioned nodes of included files, in tree order, of the types listed.
- `expected.locations`: a finding with the line and column of its range in its file.
