#!/usr/bin/env bash
# Run the illumi-search benchmark inside the reproducible podman image.
#
# This is the exact environment the capacity report (BENCHMARK_CAPACITY.md)
# is generated with. The image only ships PHP + the runtime extensions; the
# engines that need a server (mysql/pgsql/meilisearch) are reached through
# `--network=host`.
#
# Usage:
#   bench/run.sh [docs] [all-engines|file] [--mem=8g --cpus=4] [artisan args...]
#
# Examples:
#   bench/run.sh 100000 all-engines --mem=8g --cpus=4 --capacity
#   bench/run.sh 100000 all-engines --mem=2g --cpus=2 --capacity
#   bench/run.sh 100000 file --mem=1g --cpus=1 --capacity
#   bench/run.sh 1000 file --mem=8g --cpus=4 --format=json --repetitions=3
set -euo pipefail

# The mounted app is the demo app: it owns `artisan`, links the package (and
# the filament package) via composer path repos (../illumi-search), and its
# storage/ persists the index. The whole `illumi/` parent dir is mounted at
# the same absolute path so the relative path repos resolve inside the
# container.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT="$(cd "${ROOT}/.." && pwd)"
DOCS="${1:-100000}"
TARGET="${2:-file}"   # "file" or "all-engines"
shift 2 || true

MEM="8g"
CPUS="4"
ARGS=()

for arg in "$@"; do
    case "$arg" in
        --mem=*) MEM="${arg#--mem=}" ;;
        --cpus=*) CPUS="${arg#--cpus=}" ;;
        *) ARGS+=("$arg") ;;
    esac
done

CMD_ARGS=(php artisan illumi-search:benchmark --docs="${DOCS}")

if [[ "${TARGET}" == "all-engines" ]]; then
    CMD_ARGS+=("--all-engines")
fi
CMD_ARGS+=("${ARGS[@]}")

# The demo .env defaults to sqlite; the container env overrides it (Laravel
# gives shell env precedence over .env). Default to the file engine.
DRIVER="${ILLUMI_SEARCH_DRIVER:-file}"
# Meilisearch needs its master key; the demo .env keeps it commented, and the
# local podman Meilisearch (the one this report benchmarks against) uses
# "masterKey" by default. Override via ILLUMI_SEARCH_MEILISEARCH_KEY if yours differs.
MEILI_KEY="${ILLUMI_SEARCH_MEILISEARCH_KEY:-masterKey}"

echo "▶ podman run --rm --memory=${MEM} --cpus=${CPUS} --network=host"
echo "    -e ILLUMI_SEARCH_DRIVER=${DRIVER}"
echo "    -e ILLUMI_SEARCH_MEILISEARCH_KEY=${MEILI_KEY}"
echo "    -v ${PARENT}:${PARENT}"
echo "    -w ${ROOT}/../illumi-search-demo"
echo "    illumi-bench-php:8.5 ${CMD_ARGS[*]}"

podman run --rm --memory="${MEM}" --cpus="${CPUS}" --network=host \
    -e "ILLUMI_SEARCH_DRIVER=${DRIVER}" \
    -e "ILLUMI_SEARCH_MEILISEARCH_KEY=${MEILI_KEY}" \
    -v "${PARENT}:${PARENT}:Z" \
    -w "${ROOT}/../illumi-search-demo" \
    illumi-bench-php:8.5 "${CMD_ARGS[@]}"
