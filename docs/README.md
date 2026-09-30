# PHP guides

Start with the [Carve documentation](https://markup-carve.github.io/carve/)
for language syntax, examples and features shared by the implementations.
These guides cover the PHP APIs and configuration.

## Conversion and configuration

- [Command line](cli.md): subcommands, flags and migration fidelity reports.
- [Importing](html-import.md): HTML, Markdown, Djot and BBCode importers, fidelity and loss reports.
- [Markdown output](markdown-output.md): writer options.
- [Extensions](extensions.md): bundled extensions and registration.
- [Untrusted input](security.md): safe rendering options.
- [File inclusion](includes.md): opt-in includes and resolver configuration.
- [Linting](lint.md): rules and options.
- [Streaming render](streaming-render.md): chunk delivery and acceptance.
- [Stored documents](stored-documents.md): spec versions and stored content.

## Editor integration

- [ProseMirror / Tiptap](prosemirror.md): editor interchange.
- [AST JSON](ast-json.md): the interchange format.
- [AST editor sidecars](ast-editor-sidecars.md): editor metadata.
- [Editor sessions](editor-session.md): UTF-8 source edits, mapped nodes and identities.
- [Source-line tracking](source-lines.md): source positions on the AST.
- [Source-preserving patches](source-patches.md): structured formatting edits.

For contribution setup, custom renderers, implementation details and
measurements, see the [developer documentation](development.md).
