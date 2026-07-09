# carve-php Performance Benchmarks

PHP-only performance suite for carve-php. It measures parsing and rendering
throughput, memory behavior, and robustness against extreme inputs.

Cross-engine comparisons (other Carve implementations and other markup engines)
live in the dedicated [markup-carve/carve-bench](https://github.com/markup-carve/carve-bench)
repository - this suite intentionally benchmarks only this library.

## Quick Start

```bash
# Run the main benchmark
php tests/benchmark/benchmark.php

# Full suite (benchmark + memory profile + stress tests)
./tests/benchmark/run-all.sh

# Quick run (fewer iterations)
./tests/benchmark/run-all.sh --quick
```

## Scripts

| Script | Description |
|--------|-------------|
| `benchmark.php` | Main benchmark: document sizes, profiles, safe mode, parse vs render |
| `memory-profile.php` | Detailed memory analysis |
| `stress-test.php` | Edge case and stress testing |
| `generate-report.php` | HTML report generator |
| `run-all.sh` | Master runner script |

## Usage Examples

### Main Benchmark

```bash
php tests/benchmark/benchmark.php --iterations=100 --warmup=10
php tests/benchmark/benchmark.php --json > results.json
```

Measures conversion time across generated fixtures of increasing size and
complexity, compares profiles (`full`, `article`, `comment`, `minimal`),
safe mode on/off, and parse-only vs full conversion.

### Memory Profiling

```bash
php tests/benchmark/memory-profile.php
php tests/benchmark/memory-profile.php --detailed
php tests/benchmark/memory-profile.php --json
```

Profiles memory usage per fixture (including the `.crv` files in `fixtures/`),
per profile, and across document sizes to verify linear scaling. `--detailed`
adds a per-phase breakdown with AST node counts.

### Stress Testing

```bash
# Run all stress tests
php tests/benchmark/stress-test.php

# Run specific scenario
php tests/benchmark/stress-test.php --scenario=pathological
php tests/benchmark/stress-test.php --scenario=deep_nesting
```

Available scenarios:

- `deep_nesting` - Deeply nested lists (20+ levels)
- `many_paragraphs` - 10,000 paragraphs
- `huge_table` - 100x100 table (10,000 cells)
- `inline_heavy` - Paragraphs with 100+ inline elements
- `many_links` - 5,000 links with references
- `pathological` - Potential exponential edge cases
- `many_code_blocks` - 1,000 code blocks
- `many_footnotes` - 500 footnotes
- `memory_pressure` - 2MB+ documents

### Generate HTML Report

```bash
# Generate from latest results (results/php-benchmark-*.json, as written by run-all.sh)
php tests/benchmark/generate-report.php

# Generate from specific results file
php tests/benchmark/generate-report.php results/php-benchmark-20260101-120000.json
```

## Output Formats

All benchmarks support the `--json` flag for JSON output:

```bash
php benchmark.php --json | jq '.conversion.complex'
```

## Fixtures

Static Carve documents in `fixtures/`, used by the memory profiler:

| File | Description |
|------|-------------|
| `simple.crv` | Basic paragraphs, lists, links |
| `complex.crv` | Broad feature coverage |
| `stress.crv` | Extreme cases |
| `readme.crv` | Real-world README simulation |

The main benchmark generates its workloads programmatically, so results stay
comparable across runs without fixture drift.

## Metrics

### Timing Metrics

- **Mean** - Average execution time
- **Median** - Middle value (less affected by outliers)
- **P95** - 95th percentile (worst 5% of runs)
- **P99** - 99th percentile
- **Min/Max** - Range of times
- **StdDev** - Standard deviation

### Throughput

Measured in bytes per second (B/s, KB/s, MB/s).

### Memory

- **Allocated** - Memory allocated from system
- **Used** - Actually used memory
- **Peak** - Maximum memory during execution

## Interpreting Results

### Document Size Scaling

Look for linear scaling (O(n)) as document size increases. Non-linear scaling
indicates potential algorithmic issues.

### Profile Performance

Different profiles should show similar performance since they filter the same AST.

## CI Integration

Add to your CI pipeline:

```yaml
- name: Run performance benchmarks
  run: |
    php tests/benchmark/benchmark.php --iterations=50 --json > benchmark.json
    php tests/benchmark/stress-test.php
```

## Requirements

- PHP 8.2+
- Composer dependencies installed
