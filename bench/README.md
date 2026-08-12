# Benchmark — reproducible

The benchmark is the **validation criterion** for any optimization: never
claim an improvement without measuring it in this environment and comparing it
to the reference numbers in `BENCHMARK_CAPACITY.md`.

This file documents **how to run** the benchmark. The results live in
[`BENCHMARK_CAPACITY.md`](../BENCHMARK_CAPACITY.md).

## Image

`illumi-bench-php:8.5` (see `Dockerfile`) ships PHP 8.5-cli + intl + mbstring +
pcntl + pdo_sqlite/pdo_mysql/pdo_pgsql. The SQL engines reach host services via
`--network=host` (see `run.sh`).

```bash
podman build -t illumi-bench-php:8.5 -f bench/Dockerfile .
```

## Capacity report (all engines, progressive to 1M, per tier)

```bash
bench/run.sh 1000000 all-engines --mem=1g --cpus=1 --capacity --skip-rebuild-check
bench/run.sh 1000000 all-engines --mem=2g --cpus=2 --capacity --skip-rebuild-check
bench/run.sh 1000000 all-engines --mem=8g --cpus=4 --capacity --skip-rebuild-check
```

## Single engine / quick iteration

```bash
# FileEngine only, capacity
bench/run.sh 1000000 file --mem=8g --cpus=4 --capacity

# FileEngine, quick run (JSON, 3 repetitions)
bench/run.sh 1000 file --mem=8g --cpus=4 --format=json --repetitions=3
```

## Meilisearch

Meilisearch is benchmarked **separately at ≤ 10k docs** — its HTTP indexing
queue saturates beyond that (rebuild ~65 d/s), making larger capacity runs
impractical.

```bash
ILLUMI_SEARCH_DRIVER=meilisearch bench/run.sh 10000 meilisearch --mem=8g --cpus=4 \
  --capacity --steps=1000,10000 --skip-rebuild-check
```

## Without podman (direct PHP run)

Local PHP (8.5 + extensions) is equivalent to the image:

```bash
cd illumi-search-demo
ILLUMI_SEARCH_DRIVER=file php artisan illumi-search:benchmark --docs=1000000 --capacity
ILLUMI_SEARCH_DRIVER=sqlite php artisan illumi-search:benchmark --docs=1000000 --capacity
```

⚠️ Reset `ILLUMI_SEARCH_DRIVER=sqlite` afterwards, and never commit a modified
`.env`.
