import { createHash } from 'node:crypto'
import { readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { cpus, loadavg } from 'node:os'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'

const [baselineArg, candidateArg, reportArg] = process.argv.slice(2)
if (!baselineArg || !candidateArg || !reportArg) {
  throw new Error('Usage: node scripts/bench-list-chain.mjs BASELINE CANDIDATE REPORT.json')
}
const roots = { baseline: resolve(baselineArg), candidate: resolve(candidateArg) }
const worker = fileURLToPath(new URL('./bench-container-worker.php', import.meta.url))
const digest = data => createHash('sha256').update(data).digest('hex')
function sourceFingerprint(root) {
  const hash = createHash('sha256')
  function visit(path) {
    for (const entry of readdirSync(join(root, path), { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const name = `${path}/${entry.name}`
      if (entry.isDirectory()) visit(name)
      else if (entry.isFile()) hash.update(name).update('\0').update(readFileSync(join(root, name))).update('\0')
    }
  }
  visit('src')
  return hash.digest('hex')
}
const git = (root, args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim()
const sources = Object.fromEntries(Object.entries(roots).map(([variant, root]) => [variant, {
  head: git(root, ['rev-parse', 'HEAD']),
  sourceSha256: sourceFingerprint(root),
  trackedDiffSha256: digest(execFileSync('git', ['diff', 'HEAD', '--', 'src'], { cwd: root })),
}]))
if (git(roots.baseline, ['status', '--porcelain', '--ignored', '--untracked-files=all', '--', 'src']) !== '' || sources.baseline.trackedDiffSha256 !== digest('')) throw new Error('Baseline source must be clean')
const report = {
  schema: 1,
  method: 'Two reversed fresh-process rounds; PHP -n, opcache and JIT disabled. Each fixture/phase warms for 200 ms, then five batches of at least 20 ms. Parse depth and parse/render versus combined HTML checked before and after timing. Shared-host observations, no timing thresholds.',
  sources,
  runnerSha256: digest(readFileSync(new URL(import.meta.url))),
  workerSha256: digest(readFileSync(worker)),
  php: execFileSync('php', ['-n', '-v'], { encoding: 'utf8' }).split('\n')[0],
  logicalCpus: cpus().length,
  loadStart: loadavg(),
  observations: [],
}
const html = new Map()
for (const [round, order] of [['baseline', 'candidate'], ['candidate', 'baseline']].entries()) {
  for (const variant of order) {
    for (const family of ['quote', 'list']) {
      const cases = [48, 96, 192].map(size => ({ size, source: (family === 'quote' ? '> ' : '- ').repeat(size) + 'end\n' }))
      for (const phase of ['parse', 'render', 'html']) {
        const output = execFileSync('php', ['-n', '-d', 'extension=mbstring', '-d', 'extension=ctype', worker, roots[variant]], {
          input: JSON.stringify({ mode: phase, family: family === 'quote' ? 'nested-quotes' : 'nested-lists', cases }), encoding: 'utf8',
        })
        const rows = output.trim().split('\n').map(line => JSON.parse(line))
        const runtime = rows.find(row => row.event === 'runtime')
        if (!runtime || runtime.opcache || !['', '0', 'disable', 'off'].includes(runtime.jit)) throw new Error('Invalid PHP runtime')
        const results = rows.filter(row => row.event === 'result')
        if (results.length !== cases.length) throw new Error('Missing observations')
        for (const row of results) {
          if (row.status !== 'ok' || row.depth !== row.size || row.samplesMs.length !== 5 || row.samplesCpuMs.length !== 5) throw new Error('Invalid observation')
          const key = `${family}/${row.size}`
          if (html.has(key) && html.get(key) !== row.htmlSha256) throw new Error('Changed HTML fingerprint')
          html.set(key, row.htmlSha256)
          report.observations.push({ round, variant, family, phase, runtime, ...row })
        }
      }
    }
    console.error(`Round ${round}, ${variant}: recorded`)
  }
}
for (const [variant, root] of Object.entries(roots)) {
  if (sourceFingerprint(root) !== sources[variant].sourceSha256) throw new Error('Source changed during measurement')
}
if (digest(readFileSync(worker)) !== report.workerSha256) throw new Error('Worker changed during measurement')
if (report.observations.length !== 72 || html.size !== 6) throw new Error('Incomplete paired report')
report.loadEnd = loadavg()
writeFileSync(reportArg, JSON.stringify(report, null, 2) + '\n')
