# Tables on quoted list item markers

A GFM header can open on a list marker inside a blockquote:

```markdown
> - | a | b |
>   |---|---|
>   | c | d |
```

The importer now writes the header on that item's marker and the body rows at its content column. It handles bullet and ordered markers, nested quotes, nested lists, quotes inside outer list items and marker padding of one to four spaces. Five or more padding spaces start code; those rows stay code. Leading zeros in an ordered marker can change its written width, and the continuation indentation moves with it.

A noninitial ordered marker cannot interrupt an open paragraph. A row without a valid GFM delimiter remains escaped text. An unquoted line after the table leaves the quote. Table warnings retain their original source lines.

The regression set includes 57 cmark-gfm fixtures and a diagnostic-line test. It covers valid tables, rejected headers, item padding, container endings and paragraph continuation. Generated table scope attributes, inter-tag whitespace, formatter indentation and equivalent quote escaping are normalized for HTML comparison.

This is a follow-up to [#2945](https://github.com/markup-carve/carve-php/pull/2945). It does not change native table parsing or image-alt semantics. Image alt text remains literal under the current Carve specification; a table escape is retained in a native image's alt value. An HTML fallback would change safe-mode and non-HTML output, so it is excluded from this fix.
