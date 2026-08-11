# Benchmark — reproductible

Le benchmark est le **critère de validation** de toute optimisation : on ne
déclare pas une amélioration sans l'avoir mesurée dans cet environnement, et
comparée aux chiffres de référence de `BENCHMARK_CAPACITY.md`.

## Références actuelles (v1.22, FileEngine)

| Docs | Search | p50 | p95 | Rebuild | RAM |
|:----:|:------:|:---:|:---:|:-------:|:---:|
| 1,000 | 58 q/s | 7.9 ms | 44 ms | 12,578 d/s | 71 MB |
| 10,000 | 26 q/s | 8.5 ms | 146 ms | 11,477 d/s | 17 MB |
| 100,000 | 5.2 q/s | 24 ms | 814 ms | 4,586 d/s | 139 MB |
| 500,000 | 5.2 q/s | 22 ms | 842 ms | 23,474 d/s | 11 MB |
| **1,000,000** | **4.9 q/s** | **26 ms ✅** | 914 ms | 46,883 d/s | 9 MB |

Mesure : run direct workstation (`ILLUMI_SEARCH_DRIVER=file`, `--docs=1000000
--capacity`). Qualité ✅✅✅.

## Trois tiers de ressources (podman)

| Tier | Commande | Notes |
|------|----------|-------|
| **1 GiB / 1 vCPU** | `bench/run.sh 1000000 all-engines --mem=1g --cpus=1 --capacity --skip-rebuild-check` | petit tier |
| **2 GiB / 2 vCPU** | `bench/run.sh 1000000 all-engines --mem=2g --cpus=2 --capacity --skip-rebuild-check` | petit VPS |
| **8 GiB / 4 vCPU** | `bench/run.sh 1000000 all-engines --mem=8g --cpus=4 --capacity --skip-rebuild-check` | VPS standard |

Tous les résultats détaillés sont dans `BENCHMARK_CAPACITY.md`.

## Métriques humaines

Le capacity affiche (en plus des métriques classiques) :

| Métrique | Formule | Lecture |
|----------|---------|---------|
| **Req/jour\*** | search q/s × 86 400 × 0.30 | requêtes soutenables par jour à 30% de charge |
| **Idx KB/doc** | indexSizeMb × 1024 ÷ docs | efficacité de stockage |
| **RAM KB/doc** | peakRamMb × 1024 ÷ docs | dimensionnement mémoire |

Le rapport `BENCHMARK_CAPACITY.md` enrichit avec : req/jour soutenables, p50/p95
par tier, index KB/doc, RAM KB/doc, temps de re-indexation, et la comparaison
avec la baseline 2026-07 (v1.20).

## Environnement

L'image `illumi-bench-php:8.5` (Dockerfile ci-joint) reproduit le runtime :
PHP 8.5-cli + intl + mbstring + pcntl + pdo_sqlite/pdo_mysql/pdo_pgsql. Les
engines à serveur (mysql, pgsql, meilisearch) sont joints via `--network=host`.

```bash
# Build de l'image (une fois)
podman build -t illumi-bench-php:8.5 -f bench/Dockerfile .

# Rapport capacité complet (5 engines, 1M)
bench/run.sh 1000000 all-engines --mem=8g --cpus=4 --capacity --skip-rebuild-check

# FileEngine seul, capacité
bench/run.sh 1000000 file --mem=8g --cpus=4 --capacity

# FileEngine, run simple (rapide) pour itérer
bench/run.sh 1000 file --format=json --repetitions=3
```

## Alternative sans podman (run direct — la méthode de la baseline 2026-07)

PHP local (8.5 + extensions) est équivalent à l'image :

```bash
cd illumi-search-demo
ILLUMI_SEARCH_DRIVER=file php artisan illumi-search:benchmark --docs=1000000 --capacity
ILLUMI_SEARCH_DRIVER=sqlite php artisan illumi-search:benchmark --docs=1000000 --capacity
```

⚠️ Remettre `ILLUMI_SEARCH_DRIVER=sqlite` après coup, et ne jamais committer
`.env` modifié.

## Meilisearch

Meilisearch est benchmarké **séparément à ≤ 10k docs** :
```bash
ILLUMI_SEARCH_DRIVER=meilisearch bench/run.sh 10000 meilisearch --mem=8g --cpus=4 --capacity --steps=1000,10000 --skip-rebuild-check
```
Sa file d'indexation HTTP sature au-delà (tâches en échec sur v1.12) — 1M est
impraticable, comme dans le rapport 2026-07.
