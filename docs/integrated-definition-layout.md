# Integrated definition layout

Reference, footnote, and abbreviation definitions may appear anywhere in a
document, including below the text that uses them. `BlockParser` collects them
during its structural walk over the source, in the same methods that consume
the definition lines, and completes the uses that came before their definitions
once the walk ends.

## When it runs

`BlockParser::extractDefinitions()` checks the source for the opening bytes of
each family: `]:` for references, `[^` for footnotes, and `*[` for
abbreviations. A document with none of them skips definition work. Any match
turns the integrated pass on, whether one family is present or all three. The
checks are byte gates, not recognizers: the walk still decides what is a
definition.

Collecting in the walk means a definition is active only where the block
grammar reads one. A `[x]: /u` line inside a fenced code block is code, and an
unterminated fence inside a list ends when the source dedents out of the list,
so a definition after the dedent still counts. A collector that ran before the
block parser would have to reparse the source around each candidate to know
this.

## After the walk

Inline content is parsed during the walk, so a use whose definition is still
below it stays an unresolved node. `finishIntegratedDefinitionPass()` then
completes the tree without rebuilding it:

- footnote bodies collected during the walk are parsed, repeatedly, until no
  body registers another footnote;
- link and image references receive the destination, title, and attributes of
  their definition, and an explicit definition replaces an implicit heading
  reference the link had already resolved to;
- footnote references become active once their definition exists;
- abbreviations defined later in the document expand in the text above them;
- a reference image that held a caption slot becomes a figure if it resolved,
  and gives the caption lines back to its paragraph if it did not;
- `Undefined reference` and `Undefined footnote` warnings are dropped for labels
  that turned out to be defined.
