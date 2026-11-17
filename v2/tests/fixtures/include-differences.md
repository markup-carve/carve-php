# Include differences from carve-js

Where the PHP include expansion and carve-js `next2` differ, the clause decides. Every vector of `include-vectors/` passes.

| Case | Input | Clause sentence | carve-js | PHP |
| --- | --- | --- | --- | --- |
| File resolver size cap | `{{ big.crv }}`, `big.crv` holding 8,388,609 ASCII bytes, default budget | C2-N-065: "A text that would carry the sum past the cap is refused with `include-budget` (`size_exceeded`) and does not count toward the sum." | the file resolver refuses the file as `unreadable` before returning its text | the resolver returns the text; expansion reports `include-budget` |
