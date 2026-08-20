# Integrated definition layout

Mixed-definition documents used to maintain a reference/container scanner and
then run the authoritative block parser over the same source. Profiling showed
that the reference scanner alone occupied roughly a third of parse time on the
generated mixed corpus.

The production path now uses one authoritative structural walk when at least
two definition families are present. That walk emits reference, footnote, and
abbreviation definitions from the methods that consume their source lines.
Inline syntax is still parsed immediately because figure and table structure
depends on its node shape. Only resolution that needs a later definition is
finished after the structural walk:

- unresolved link and image nodes receive their destination, title, and
  definition attributes;
- unresolved footnote references become active after their definition exists;
- eligible text nodes receive abbreviations discovered later in the document.

A document with zero definition families skips definition work. A document
with one family retains its specialized collector, which is cheaper than the
integrated bookkeeping for that workload. Public node and renderer contracts
remain unchanged.

This also removes a disagreement in malformed input: an unterminated fenced
block inside a list ends when the source dedents out of that list. The old
reference prepass remained opaque after the dedent and silently disabled every
later definition, while the authoritative block grammar correctly continued.
Definition activation now follows the block grammar.
