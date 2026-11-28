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
const { writeTreeWithStats } = await import(pathToFileURL(resolve(root, 'src/write.ts')).href)

// JS_TRACE_COMPARES=1: one record per `compareGroup` instead, with the writer state it starts from and what its check
// asks, each node as its path from the document (`/children/0/children/3`) and each offset in UTF-8 bytes of the value.
const COMPARES = process.env.JS_TRACE_COMPARES === '1'
let compares = []
let paths = new WeakMap()

const indexPaths = (node, path) => {
  paths.set(node, path)
  for (const [key, value] of Object.entries(node)) {
    if (key !== 'attrs' && value !== null && typeof value === 'object' && !Array.isArray(value) && typeof value.type === 'string') {
      indexPaths(value, `${path}/${key}`)
      continue
    }
    if (!Array.isArray(value)) continue
    value.forEach((child, at) => {
      if (child !== null && typeof child === 'object' && typeof child.type === 'string') indexPaths(child, `${path}/${key}/${at}`)
    })
  }
}
const pathOf = (node) => paths.get(node) ?? '?'
const bytesAt = (node, at) => typeof node.value === 'string' ? Buffer.byteLength(node.value.slice(0, at)) : at
const escapeAt = ([node, at]) => [pathOf(node), bytesAt(node, at)]

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
  resync(k, offset, j, start, failedEnd, own, source) {
    const src = Buffer.from(source ?? '').subarray(offset, offset + 30).toString()
    process.stderr.write(`  k=${k} off=${offset} j=${j} aStart=${start ?? 'n'} failedEnd=${failedEnd} own=${Buffer.byteLength(own)}` +
      ` src=${JSON.stringify(src)} own=${JSON.stringify(own.slice(0, 30))}\n`)
  },
  respell(e, flags) {
    process.stderr.write(`RESPELL ${pathOf(e)} flags=${flags}\n`)
  },
  probe(e, flags, source) {
    process.stderr.write(`PROBE ${pathOf(e)} flags=${flags} src=${JSON.stringify(source)}\n`)
  },
  state(w, document) {
    if (!COMPARES) return null
    if (!paths.has(document)) indexPaths(document, '')
    return {
      escapes: [...w.escapes].flatMap(([node, offsets]) => offsets.map((at) => escapeAt([node, at]))),
      forms: [...w.forms].map(([node, form]) => [pathOf(node), form]),
      attempts: [...w.attempts].map(([node, attempt]) => [pathOf(node), attempt.step, attempt.escapes.map(escapeAt)]),
      lost: [...w.lost].map(pathOf),
      lines: [...w.lines],
      kindRounds: w.kindRounds,
      starts: [...w.starts].map(([node, place]) => [pathOf(node), place]),
      bracedAnyway: [...w.bracedAnyway].map(pathOf),
      lostKeys: [...w.lostKeys].map(([node, keys]) => [pathOf(node), [...keys]]),
    }
  },
  compareDone(w, document, group, first, check, state) {
    if (!COMPARES) return
    compares.push({
      group: group.map(pathOf),
      source: `${first ? '' : '\n'}${w.lines.join('\n')}\n`,
      state,
      escapes: check.escapes.map(escapeAt),
      trials: check.trials.map(escapeAt),
      constructs: [...check.constructs].map(escapeAt),
      retracted: check.retracted.map(escapeAt),
      forms: check.forms.map(pathOf),
      losses: check.losses.map(([node, field]) => [pathOf(node), field]),
      misaligned: check.misaligned,
      attempts: [...w.attempts].map(([node, attempt]) => [pathOf(node), attempt.step, attempt.escapes.map(escapeAt)]),
    })
  },
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

// A `.jsonl` argument holds one `{"id", "input"}` or `{"id", "tree"}` row per line.
const items = inputs.flatMap((file) => file.endsWith('.jsonl')
  ? readFileSync(file, 'utf8').split('\n').filter((line) => line !== '').map((line) => JSON.parse(line))
  : [{ id: basename(file), input: readFileSync(file, 'utf8') }])

for (const item of items) {
  input = item.input
  rounds = []
  compares = []
  paths = new WeakMap()
  current = null
  // A row with a `tree` is written on the repair path instead (RepairReplayTest), the way `writeTreeWithStats` does.
  const output = item.tree === undefined
    ? format(input, {})
    : writeTreeWithStats({ type: 'document', astVersion: '2.0', children: item.tree }, undefined, 'repair').output.source
  if (COMPARES) {
    compares.forEach((record, at) => process.stdout.write(JSON.stringify({ input: item.id, at, ...record }) + '\n'))
    continue
  }
  process.stdout.write(JSON.stringify({
    input: item.id,
    output: createHash('md5').update(output).digest('hex').slice(0, 8),
    rounds,
  }) + '\n')
}
