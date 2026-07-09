#!/bin/bash
#
# carve-php Complete Benchmark Suite Runner
#
# Usage:
#   ./run-all.sh [--quick] [--full]
#
# Cross-engine comparisons live in the dedicated markup-carve/carve-bench repo.
#

set -e
cd "$(dirname "$0")"

ITERATIONS=50
WARMUP=10
QUICK=false
FULL=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --quick)
            QUICK=true
            ITERATIONS=10
            WARMUP=3
            shift
            ;;
        --full)
            FULL=true
            ITERATIONS=100
            WARMUP=20
            shift
            ;;
        --help)
            echo "carve-php Benchmark Suite Runner"
            echo ""
            echo "Usage: ./run-all.sh [options]"
            echo ""
            echo "Options:"
            echo "  --quick     Quick run with fewer iterations (10 iters, 3 warmup)"
            echo "  --full      Full run with more iterations (100 iters, 20 warmup)"
            echo "  --help      Show this help"
            echo ""
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

echo "========================================"
echo "carve-php Performance Benchmark Suite"
echo "========================================"
echo ""
echo "Configuration:"
echo "  Iterations: $ITERATIONS"
echo "  Warmup: $WARMUP"
echo "  Quick mode: $QUICK"
echo ""

# Create results directory
mkdir -p results

# Run PHP benchmark
echo "----------------------------------------"
echo "1. Running Benchmark"
echo "----------------------------------------"
php benchmark.php --iterations=$ITERATIONS --warmup=$WARMUP
echo ""

# Run memory profiler
echo "----------------------------------------"
echo "2. Running Memory Profiler"
echo "----------------------------------------"
php memory-profile.php
echo ""

# Run stress tests (quick mode only runs a subset)
echo "----------------------------------------"
echo "3. Running Stress Tests"
echo "----------------------------------------"
if [ "$QUICK" = true ]; then
    php stress-test.php --scenario=deep_nesting
    php stress-test.php --scenario=pathological
else
    php stress-test.php
fi
echo ""

# Generate JSON results for storage
echo "----------------------------------------"
echo "Saving Results"
echo "----------------------------------------"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
php benchmark.php --iterations=$ITERATIONS --warmup=$WARMUP --json > "results/php-benchmark-$TIMESTAMP.json"
echo "Saved: results/php-benchmark-$TIMESTAMP.json"

echo ""
echo "========================================"
echo "Benchmark Complete!"
echo "========================================"
echo ""
echo "To generate an HTML report:"
echo "  php generate-report.php"
echo ""
