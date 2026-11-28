// The carve-js side of the planner trace harness (PlannerTraceTest): formats each input with carve-js and prints one
// JSON line per repair round with the constructs the round reported, its trials and the escapes it placed.
// Usage: node js-trace.mjs <carve-js checkout> <input.crv | rows.jsonl>... > trace.jsonl (the checkout needs node_modules with tsx).
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { register } from 'node:module'
import { basename, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const [root, ...inputs] = process.argv.slice(2)
const { register: registerTsx } = await import(pathToFileURL(resolve(root, 'node_modules/tsx/dist/esm/api/index.mjs')).href)
registerTsx()
register(pathToFileURL(resolve(import.meta.dirname, 'js-trace-hooks.mjs')).href)
const { format } = await import(pathToFileURL(resolve(root, 'src/index.ts')).href)

let input = ''
let rounds = []
let current = null

// An escape as the byte offset of its character in the input, the character, and its line and column there.
const escapeOf = ([node, at]) => {
  const start = node.pos?.startOffset ?? -1
  const offset = start < 0 ? -1 : start + Buffer.byteLength(node.value.slice(0, at))
  return [offset, node.value[at] ?? '']
}
const sorted = (escapes) => escapes.map(escapeOf).sort((a, b) => a[0] - b[0] || (a[1] < b[1] ? -1 : 1))

globalThis.__plannerTrace = {
  compared(w, check, group) {
    const source = `${w.lines.join('\n')}\n`
    current = {
      group: group[0]?.pos?.startOffset ?? -1,
      source: createHash('md5').update(source).digest('hex').slice(0, 8),
      constructs: sorted([...check.constructs]),
    }
    rounds.push(current)
  },
  before(w) {
    return new Map([...w.escapes].map(([node, offsets]) => [node, new Set(offsets)]))
  },
  // The trials the round kept and the escapes it added to the writer's escapes.
  placed(w, check, before) {
    if (current === null) return
    current.trials = sorted(check.trials)
    const added = []
    for (const [node, offsets] of w.escapes) for (const at of offsets) if (before.get(node)?.has(at) !== true) added.push([node, at])
    current.placed = sorted(added)
  },
}

// A `.jsonl` argument holds one `{"id", "input"}` row per line.
const items = inputs.flatMap((file) => file.endsWith('.jsonl')
  ? readFileSync(file, 'utf8').split('\n').filter((line) => line !== '').map((line) => JSON.parse(line))
  : [{ id: basename(file), input: readFileSync(file, 'utf8') }])

for (const item of items) {
  input = item.input
  rounds = []
  current = null
  const output = format(input, {})
  process.stdout.write(JSON.stringify({
    input: item.id,
    output: createHash('md5').update(output).digest('hex').slice(0, 8),
    rounds,
  }) + '\n')
}
