# Rendering options

## Straight quotes with smart typography


Set `SmartTypographyMode::QuotesSource` with `setSmartTypography()` on an
HTML, Markdown, plain-text, or ANSI renderer. It emits source runs for quotes
and apostrophes while keeping other smart substitutions enabled. The
`CarveConverter` constructor also accepts this mode as `smartTypography`.
`Glyph` remains the default and `Source` still emits every source run.
Parsing and heading IDs are unchanged; typed curly quotes and escapes keep
their existing behavior. The CLI accepts `--smart-typography quotes-source`.
