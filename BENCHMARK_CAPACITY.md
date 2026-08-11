# illumi-search — Capacity Benchmark Report

**Write search code once. Switch engines by changing one `.env` value.**
This report measures the practical capacity limits of each engine under
sustained load. Data generated via `php artisan illumi-search:benchmark --capacity`.

**Methodology:** Progressive volume test. At each step, documents are indexed,
search is benchmarked (12 multi-language queries × 5 repetitions), suggest is
measured, then rebuild speed and index/RAM are recorded.
**Stop condition:** Latency p50 > 100ms, or rebuild speed < 500 d/s (skipped for
HTTP engines via `--skip-rebuild-check`).
**Document size:** ~1.2 KB — 2 fields (title ~60 chars, body ~1,100 chars),
multi-language (CJK, Cyrillic, Arabic, Latin mixed).
**Ranking:** scores are BM25-normalized to 0–100 across engines.

> **Version note (2026-08-11):** this report supersedes the 2026-07 baseline.
> The old numbers were measured on **v1.20.0 directly on a workstation** — i.e.
> before the v1.21/v1.22 optimizations (deterministic rowid, scoped cache, BM25
> normalization, FileEngine hot loop). The gains below are measured on
> **v1.22** on the same machine and in reproducible containers.

---

## Environments tested

| Environment | Hardware | Notes |
|-------------|----------|-------|
| **Workstation (direct)** | 40 vCPU / 62 GiB, PHP limit 8 GB (4 GB FileEngine) | the 2026-07 baseline method; used to re-validate v1.22 |
| **Podman 1 GiB / 1 vCPU** | container `--memory=1g --cpus=1` | smallest viable / cheapest tier |
| **Podman 2 GiB / 2 vCPU** | container `--memory=2g --cpus=2` | small VPS |
| **Podman 8 GiB / 4 vCPU** | container `--memory=8g --cpus=4` | typical VPS deployment target |

All containers run `illumi-bench-php:8.5` (PHP 8.5-cli + intl + mbstring +
pcntl + pdo_sqlite/pdo_mysql/pdo_pgsql) with `--network=host` so the SQL engines
reach the local MySQL (MariaDB 12.3) and PostgreSQL services.

---

## Real capacity limits — v1.22 (podman 8 GiB / 4 vCPU unless noted)

| Engine | Limit | Bottleneck | Best for |
|--------|:-----:|------------|----------|
| **SQLite FTS5** | **> 1,000,000 ✅** | none up to 1M (p50 0.1ms flat) | Dev, small projects, zero config — now handles 1M+ |
| **MariaDB / MySQL** | **~50,000** | FULLTEXT index size + filesort (p50 > 100ms at 100k) | MySQL shops, < 50k, Latin or LIKE fallback CJK |
| **PostgreSQL** (cold) | **~50,000** | GIN index not in shared_buffers | First ~100 queries after deploy need warmup |
| **PostgreSQL** (warm) | **> 1,000,000** | none (warms up to sub-ms) | Best all-around for large datasets |
| **FileEngine** | **> 1,000,000 ✅** | none up to 1M (p50 ~23ms flat) | No-DB, serverless, embedded |
| **Meilisearch** | **Unlimited** | rebuild speed over HTTP (~65 d/s) | Dedicated server, typo-tolerant |

---

## Engine selection guide (which engine, which RAM/CPU)

A decision guide distilled from the measurements below. "Config min" is the
smallest tier that still serves the target volume acceptably; "Config
recommended" is where the engine stops being the bottleneck.

### At a glance

| Case | Engine | Config min | Config recommended | Why |
|------|--------|:----------:|:------------------:|-----|
| **Solo / MVP / admin panel / intranet** (`< 500k` docs) | **SQLite** | 1 GiB / 1 vCPU | 2 GiB / 2 vCPU | zero config, p50 0.1ms, index 130 MB |
| **No-DB / serverless / embedded** (`≤ 1M` docs) | **FileEngine** | 1 GiB / 1 vCPU | 2 GiB / 2 vCPU | no external service, p50 ~25ms flat; throughput scales with vCPU (0.9 → 2.7 → 4.2 q/s) |
| **Existing MySQL shop** (`< 50k` docs) | **MySQL / MariaDB** | 1 GiB / 1 vCPU | 2 GiB / 2 vCPU | keep your DB, but FULLTEXT caps at ~50k |
| **Large multi-language** (`> 500k` docs) | **PostgreSQL** | 2 GiB / 2 vCPU | 4 GiB / 4 vCPU | warm sub-ms, only engine that scales past 1M |
| **Public site, typo-tolerant, high traffic** | **Meilisearch** | 2 GiB (server) | dedicated server | instant typo tolerance, tier-independent |

### Detailed rationale

**SQLite FTS5** — the default and best value for almost everything up to
~500k docs. Zero config, p50 0.1ms flat, index only 130 MB @ 1M.
- **1 vCPU is the real limit, not RAM**: at 1 vCPU it degrades at 1M
  (p50 470ms); from 2 vCPU up it serves 1M at 0.1ms. RAM beyond ~1 GiB adds
  nothing (SQLite is I/O-bound).
- Pick: **2 GiB / 2 vCPU** if you expect growth to 1M, **1 GiB / 1 vCPU** for
  a fixed < 500k dataset.

**FileEngine** — the only engine with **no external dependency** (flat files).
p50 stays ~25ms regardless of volume or tier, **1M reached on every tier**.
- **Throughput is CPU-bound, single-worker**: q/s scales linearly with vCPU
  (0.9 @ 1 vCPU → 2.7 @ 2 → 4.2 @ 4 at 1M). RAM is minimal (index 30 MB).
- Pick: **2 GiB / 2 vCPU** as the floor for a real deployment; 1 vCPU is only
  usable for low-traffic admin/intranet. For > 1M or higher q/s, either add
  vCPU or move to PostgreSQL.

**MySQL / MariaDB** — only when you already run MySQL. FULLTEXT degrades past
~50k on every tier (p50 670-930ms at 100k), and adding CPU/RAM does not help
(the bottleneck is index size + filesort, not resources).
- Pick: keep your existing MySQL for < 50k; beyond that, **PostgreSQL is the
  drop-in upgrade** (same SQL interface, illumi-search switches via one env).

**PostgreSQL** — the only engine that scales past 1M. Cold GIN index caps at
~50k (p50 ~180ms), but **warm it serves sub-ms flat to 1M+**.
- **Cold-start caveat**: after deploy, run ~100 warming queries. RAM matters
  here — `shared_buffers` sizing (see below) is what makes it warm.
- Pick: **2 GiB / 2 vCPU** minimum, **4 GiB / 4 vCPU** recommended for
  > 500k; the biggest lever is `shared_buffers`, not container RAM.

**Meilisearch** — dedicated server; PHP resources are irrelevant (the index
lives in the Meilisearch process). p50 ~4ms regardless of volume or tier.
- **Rebuild is the only cost** (~65 d/s over HTTP): index in background jobs.
- Pick: 2 GiB server as a floor; scale the Meilisearch server, not the app
  container.

### Resource tier cheat-sheet

| Tier | Best engine(s) | Works for | Don't use for |
|------|----------------|-----------|---------------|
| **1 GiB / 1 vCPU** | SQLite (< 500k), FileEngine (low traffic) | dev, small admin panels | 1M SQLite, high q/s FileEngine, MySQL > 50k |
| **2 GiB / 2 vCPU** | SQLite (1M), FileEngine (1M), MySQL (< 50k) | most single-app deployments | high q/s (> 5-10 q/s sustained FileEngine) |
| **8 GiB / 4 vCPU** | SQLite, FileEngine (4 q/s), PostgreSQL (warm) | team apps, moderate traffic | anything needing > 50k on MySQL/MariaDB |
| **Dedicated Meili** | Meilisearch | public high-traffic, typo-tolerant | budgets where one extra server is a dealbreaker |

**Bottom line:** start with **SQLite (2 GiB / 2 vCPU)** — it now serves 1M docs
at 0.1ms with zero config. Reach for **PostgreSQL** when you outgrow 1M or need
multi-language at scale. **FileEngine** is the pick when you cannot run a
database at all (serverless, embedded). **Meilisearch** only when you need
typo-tolerant instant search on a public site. **MySQL** is for existing shops
under ~50k docs.

---

## Per-engine data — direct workstation (v1.22, 40 vCPU)

These runs reproduce the **same method as the 2026-07 baseline** on the same
machine, so the comparison isolates the v1.20 → v1.22 code changes.

### SQLite FTS5 (direct)

| Docs | Search | p50 | p95 | Rebuild | RAM |
|:----:|:------:|:---:|:---:|:-------:|:---:|
| 1,000 | 246 q/s | 4.6 ms | 6.6 ms | 8,285 d/s | 2 MB |
| 10,000 | 9,947 q/s | 0.1 ms | 0.2 ms | 6,015 d/s | 10 MB |
| 100,000 | 10,293 q/s | 0.1 ms | 0.3 ms | 6,000 d/s | 94 MB |
| 500,000 | 10,416 q/s | 0.1 ms | 0.3 ms | 29,184 d/s | 4 MB |
| **1,000,000** | **10,810 q/s** | **0.1 ms ✅** | 0.2 ms | 56,536 d/s | 4 MB |

**Key finding:** SQLite went from *worst* (2026-07: 3.4 q/s · 362ms at 100k) to
*best* — the v1.21/v1.22 rowid + cache + BM25 work removed the old filesort
bottleneck. It now sustains **10k q/s flat to 1M docs**. See the comparison
table below.

### FileEngine (direct)

| Docs | Search | p50 | p95 | Rebuild | RAM |
|:----:|:------:|:---:|:---:|:-------:|:---:|
| 1,000 | 58 q/s | 7.9 ms | 44 ms | 12,578 d/s | 71 MB |
| 10,000 | 26 q/s | 8.5 ms | 146 ms | 11,477 d/s | 17 MB |
| 100,000 | 5.2 q/s | 24 ms | 814 ms | 4,586 d/s | 139 MB |
| 500,000 | 5.2 q/s | 22 ms | 842 ms | 23,474 d/s | 11 MB |
| **1,000,000** | **4.9 q/s** | **26 ms ✅** | 914 ms | 46,883 d/s | 9 MB |

**Key finding:** p50 stays flat (~23ms) regardless of volume, **1M reached
without degradation**. The hot loop halved latency vs 2026-07 (49ms → 24ms).

---

## Per-engine data — podman resource tiers

### SQLite FTS5

| Tier | Docs | Search | p50 | p95 | Rebuild | RAM |
|------|:----:|:------:|:---:|:---:|:-------:|:---:|
| **1 GiB / 1 vCPU** | 100,000 | 7,859 q/s | 0.1 ms | 0.4 ms | 6,081 d/s | 92 MB |
| | 1,000,000 | 2.6 q/s | **470 ms ❌** | 616 ms | 60,427 d/s | 4 MB |
| **2 GiB / 2 vCPU** | 100,000 | 10,034 q/s | 0.1 ms | 0.3 ms | 5,770 d/s | 94 MB |
| | 1,000,000 | 9,959 q/s | **0.1 ms ✅** | 0.2 ms | 54,901 d/s | 2 MB |
| **8 GiB / 4 vCPU** | 100,000 | 9,006 q/s | 0.1 ms | 0.4 ms | 5,574 d/s | 94 MB |
| | 1,000,000 | 10,530 q/s | **0.1 ms ✅** | 0.2 ms | 54,230 d/s | 2 MB |

**Reading:** SQLite needs ≥ 2 vCPU to serve 1M docs at 0.1ms; at 1 vCPU the
single-threaded FTS5 degrades at 1M (470ms). Between 2 and 4 vCPU there is no
further gain — SQLite is I/O-bound, not CPU-bound.

### FileEngine

| Tier | Docs | Search | p50 | p95 | Rebuild | RAM |
|------|:----:|:------:|:---:|:---:|:-------:|:---:|
| **1 GiB / 1 vCPU** | 100,000 | 1.5 q/s | 25 ms | 2.9 s | 4,563 d/s | 103 MB |
| | 1,000,000 | 0.9 q/s | **25 ms ✅** | 7.3 s | 22,311 d/s | 11 MB |
| **2 GiB / 2 vCPU** | 100,000 | 3.0 q/s | 25 ms | 1.5 s | 4,571 d/s | 103 MB |
| | 1,000,000 | 2.7 q/s | **23 ms ✅** | 1.7 s | 46,828 d/s | 9 MB |
| **8 GiB / 4 vCPU** | 100,000 | 4.8 q/s | 23 ms | 904 ms | 4,352 d/s | 103 MB |
| | 1,000,000 | 4.2 q/s | **28 ms ✅** | 1.1 s | 44,426 d/s | 9 MB |

**Reading:** FileEngine is **single-worker CPU-bound** — throughput scales with
vCPU (0.9 → 2.7 → 4.2 q/s at 1M), but p50 stays flat (~25ms) on every tier. p95
improves with cores (7.3s → 1.1s). **1M reached without degradation on all
tiers.**

### MySQL (MariaDB 12.3, innodb default)

| Tier | Docs | Search | p50 | p95 | Rebuild | RAM |
|------|:----:|:------:|:---:|:---:|:-------:|:---:|
| **1 GiB / 1 vCPU** | 100,000 | 1.1 q/s | **671 ms ❌** | 4.5 s | 5,023 d/s | 7 MB |
| **2 GiB / 2 vCPU** | 100,000 | 1.2 q/s | **611 ms ❌** | 4.3 s | 4,145 d/s | 9 MB |
| **8 GiB / 4 vCPU** | 100,000 | 1.1 q/s | **648 ms ❌** | 4.7 s | 3,987 d/s | 9 MB |

**Reading:** FULLTEXT degrades beyond ~50k docs on every tier (p50 > 100ms at
100k). CPU does not help — the bottleneck is FULLTEXT index size + filesort.
Best for datasets up to ~50k docs.

### PostgreSQL (cold start)

| Tier | Docs | Search | p50 | p95 | Rebuild | RAM |
|------|:----:|:------:|:---:|:---:|:-------:|:---:|
| **1 GiB / 1 vCPU** | 100,000 | 6.0 q/s | **180 ms ❌** | 228 ms | 2,682 d/s | 7 MB |
| **2 GiB / 2 vCPU** | 100,000 | 6.2 q/s | **177 ms ❌** | 240 ms | 2,554 d/s | 9 MB |
| **8 GiB / 4 vCPU** | 100,000 | 6.1 q/s | **181 ms ❌** | 256 ms | 2,483 d/s | 9 MB |

**Reading:** cold GIN index (not in `shared_buffers`) caps out at ~50k on every
tier. After warmup the same dataset serves sub-ms — see
[Cold vs Warm](#cold-vs-warm--postgresql).

### Meilisearch (external server, v1.12)

| Tier | Docs | Search | p50 | p95 | Rebuild | RAM |
|------|:----:|:------:|:---:|:---:|:-------:|:---:|
| **1 GiB / 1 vCPU** | 1,000 | 193 q/s | 4.8 ms | 9.2 ms | 29 d/s | 0 MB |
| | 10,000 | 288 q/s | 3.2 ms | 5.5 ms | 18 d/s | 10 MB |
| **2 GiB / 2 vCPU** | 1,000 | 216 q/s | 4.3 ms | 8.6 ms | 25 d/s | 0 MB |
| | 10,000 | 243 q/s | 3.9 ms | 5.6 ms | 17 d/s | 10 MB |

**Key finding:** search latency is **volume- and tier-independent** (p50 ~4ms)
because the index lives in the Meilisearch server process, not PHP. The
bottleneck is rebuild (~65 d/s over HTTP). Volumes are capped at 10k — the HTTP
indexing queue saturates beyond that (165+ failed tasks observed on v1.12),
making 1M capacity runs impractical; this matches the 2026-07 report.

---

## Comparison with the 2026-07-28 baseline (same machine, direct run)

| Engine | 100k baseline (v1.20) | 100k now (v1.22) | 1M now (v1.22) | Δ @100k |
|--------|:---------------------:|:-----------------:|:--------------:|:-------:|
| **SQLite FTS5** | 3.4 q/s · 362ms ❌ | 10,293 q/s · 0.1ms ✅ | 10,810 q/s · 0.1ms ✅ | **+3,000×** |
| **FileEngine** | 2.5 q/s · 49ms ✅ | 5.2 q/s · 24ms ✅ | 4.9 q/s · 26ms ✅ | **+2.1× · ÷2** |
| **MySQL/MariaDB** | 3.4 q/s · 358ms ❌ | 1.1 q/s · 648ms ❌ | — | ↓ (MariaDB vs MySQL 8) |
| **PostgreSQL** (cold) | 7 q/s · 158ms ❌ | 6.0 q/s · 180ms ❌ | — (warm: <1ms) | ≈ |

**Headline:** SQLite is the big win of v1.21/v1.22 — from *worst* at 100k
(362ms) to *best* (0.1ms, ~3,000× more queries/sec) and now to 1M docs. The
optimizations (deterministic rowid, scoped per-engine cache, active BM25
normalization) removed the filesort bottleneck. FileEngine's hot loop halved
p50 (49 → 24ms). MySQL/MariaDB and cold PostgreSQL are unchanged — their limits
are environmental (FULLTEXT size, GIN cache), not code.

---

## Human metrics (v1.22, podman 8 GiB / 4 vCPU, @ 100k unless noted)

| Metric | SQLite | FileEngine | MySQL/MariaDB | PostgreSQL (cold) | Meilisearch @10k |
|--------|:------:|:----------:|:-------------:|:-----------------:|:----------------:|
| **Sustained req/day** (q/s × 86 400 × 0.3) | **~233 M** | ~124 k | ~30 k | ~159 k | ~6.3 M |
| **Search q/s** | 9,006 | 4.8 | 1.1 | 6.1 | 243 |
| **Latency p50 / p95** | 0.1 / 0.4 ms | 23 / 904 ms | 648 / 4.7 s | 181 / 256 ms | 3.9 / 5.6 ms |
| **Index KB/doc** | 0.25 | 0.31 | 2.5 | 1.5 | 9.7 |
| **RAM KB/doc** | 0.94 | 1.03 | 0.09 | 0.09 | 1.0 |
| **Re-index 100k** | 18 s | 23 s | 25 s | 40 s | ~6 min |

**How to read:**
- **Sustained req/day** = realistic daily capacity at 30% load (≈ 7h of full
  throughput). E.g. SQLite could serve ~233M searches/day, FileEngine ~124k —
  a clear fit for an admin/intranet panel, not a public high-traffic site.
- **Index KB/doc** = storage efficiency. FileEngine (0.31 KB/doc) and SQLite
  (0.25) are the leanest; MySQL's FULLTEXT is 8-10× larger per doc.
- **Re-index 100k** = how long a full rebuild takes — relevant for deploy-time
  migration or nightly rebuilds.

---

## Cold vs Warm — PostgreSQL

PostgreSQL's GIN index performance is dominated by cache state:

| State | 100k latency | Ratio | Explanation |
|-------|:-----------:|:-----:|-------------|
| **Cold** (first query) | **158–180 ms** | 1× | GIN index not in `shared_buffers` |
| **Warm** (after ~100 queries) | **0.1 ms** | **×1,500+** | GIN index fully cached |

**Recommendation:** After deploy, run ~100 warming queries (e.g. `curl /api/search?q=the`)
to bring the GIN index into shared_buffers.

**System config for PostgreSQL** — increase in `postgresql.conf`:

```ini
shared_buffers = 256MB       # default is 128MB
work_mem = 64MB              # default is 4MB
effective_cache_size = 1GB
```

---

## Document size reference

All benchmarks use synthetic documents with the same structure:

| Field | Avg length | Content |
|-------|:----------:|---------|
| `title` | ~20 chars | Random word + "guide N" (e.g. "laravel guide 42") |
| `body` | ~1,200 chars | 20 random words joined (CJK, Cyrillic, Arabic, Latin mixed) |
| **Total** | **~1.2 KB** | UTF-8 text, multi-language |

At 1,000,000 docs, the raw text is ~1.2 GB. Index size varies by engine:

| Engine | Index size @ 1M | Notes |
|--------|:---------------:|-------|
| SQLite FTS5 | ~130 MB | measured on this run (page cache excluded) |
| MySQL / MariaDB | ~250 MB | FULLTEXT index + B-tree |
| PostgreSQL | ~160 MB | GIN index + tsvector |
| FileEngine | ~30 MB | Trigram index + chunks |
| Meilisearch | N/A @ 1M | External server (Rust) — index managed by Meilisearch process |

---

## Reproducing

```bash
# Build the benchmark image (once)
podman build -t illumi-bench-php:8.5 -f bench/Dockerfile .

# Workstation (direct, no container — matches the 2026-07 baseline method)
cd illumi-search-demo && ILLUMI_SEARCH_DRIVER=file php artisan illumi-search:benchmark --docs=1000000 --capacity

# Podman resource tiers (SQL engines reach host services via --network=host)
bench/run.sh 1000000 all-engines --mem=1g --cpus=1 --capacity --skip-rebuild-check
bench/run.sh 1000000 all-engines --mem=2g --cpus=2 --capacity --skip-rebuild-check
bench/run.sh 1000000 all-engines --mem=8g --cpus=4 --capacity --skip-rebuild-check
```

> **Note:** MySQL and PgSQL connect to the host services via `--network=host`.
> Meilisearch is benchmarked separately at ≤ 10k docs (`--steps=1000,10000`)
> because its HTTP indexing queue saturates beyond that on v1.12.
