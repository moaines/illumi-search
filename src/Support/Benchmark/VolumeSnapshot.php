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
    }
}
