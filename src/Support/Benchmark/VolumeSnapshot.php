<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

class VolumeSnapshot
{
    public readonly int $docs;
    public readonly float $totalTimeMs;
    public readonly float $searchQps;
    public readonly float $latencyP50;
    public readonly float $latencyP95;
    public readonly float $latencyP99;
    public readonly float $suggestQps;
    public readonly float $rebuildDocsPerSec;
    public readonly int $indexSizeMb;
    public readonly int $peakRamMb;
    public readonly array $quality;

    /** Estimated sustained search requests per day (q/s × 86 400, 30% load). */
    public readonly int $requestsPerDay;
    /** Index size per document in KB. */
    public readonly float $indexKbPerDoc;
    /** Peak RAM per document in KB. */
    public readonly float $ramKbPerDoc;
    /** How long it would take to re-index the volume once, in seconds. */
    public readonly float $rebuildSeconds;

    public function __construct(
        int $docs,
        float $totalTimeMs,
        float $searchQps,
        float $latencyP50,
        float $latencyP95,
        float $latencyP99,
        float $suggestQps,
        float $rebuildDocsPerSec,
        int $indexSizeMb,
        int $peakRamMb,
        array $quality,
    ) {
        $this->docs = $docs;
        $this->totalTimeMs = $totalTimeMs;
        $this->searchQps = $searchQps;
        $this->latencyP50 = $latencyP50;
        $this->latencyP95 = $latencyP95;
        $this->latencyP99 = $latencyP99;
        $this->suggestQps = $suggestQps;
        $this->rebuildDocsPerSec = $rebuildDocsPerSec;
        $this->indexSizeMb = $indexSizeMb;
        $this->peakRamMb = $peakRamMb;
        $this->quality = $quality;

        $this->requestsPerDay = (int) round($searchQps * 86_400 * 0.30);
        $this->indexKbPerDoc = $docs > 0 ? round(($indexSizeMb * 1024) / $docs, 2) : 0;
        $this->ramKbPerDoc = $docs > 0 ? round(($peakRamMb * 1024) / $docs, 2) : 0;
        $this->rebuildSeconds = $rebuildDocsPerSec > 0 ? round($docs / $rebuildDocsPerSec, 1) : 0;
    }
}
