<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class ReportRenderer
{
    private array $allResults = [];

    public function addEngineResults(string $engineName, array $results): void
    {
        $this->allResults[$engineName] = $results;
    }

    public function render(OutputInterface $output, string $format = 'table'): void
    {
        if ($format === 'json') {
            $output->writeln(json_encode($this->allResults, JSON_PRETTY_PRINT));
            return;
        }

        $engines = array_keys($this->allResults);
        if (empty($engines)) {
            $output->writeln('<error>No results to display.</error>');
            return;
        }

        $this->renderSection($output, $engines, 'quantity', "\n📊  Quantity (higher is better)");
        $this->renderSection($output, $engines, 'quality', "\n🎯  Quality (higher is better)");
        $this->renderSection($output, $engines, 'soundness', "\n🧠  Soundness (expected behaviour)");
    }

    private function renderSection(OutputInterface $output, array $engines, string $section, string $title): void
    {
        $metrics = array_keys($this->allResults[$engines[0]][$section] ?? []);
        if (empty($metrics)) {
            return;
        }

        $hasUnits = false;
        foreach ($metrics as $m) {
            foreach ($engines as $name) {
                $data = $this->allResults[$name][$section][$m] ?? null;
                if (! empty($data['unit'] ?? '')) {
                    $hasUnits = true;
                    break 2;
                }
            }
        }

        $output->writeln($title);

        if ($hasUnits) {
            $table = new Table($output);
            $headers = ['Metric'];
            foreach ($engines as $name) {
                $headers[] = $name;
                $headers[] = '';
            }
            $table->setHeaders($headers);
            $rows = [];
            foreach ($metrics as $m) {
                $row = [$m];
                foreach ($engines as $name) {
                    $d = $this->allResults[$name][$section][$m] ?? null;
                    if ($d === null) {
                        $row[] = '-';
                        $row[] = '';
                    } else {
                        $row[] = (string) ($d['value'] ?? $d['display'] ?? '-');
                        $row[] = $d['unit'] ?? '';
                    }
                }
                $rows[] = $row;
            }
            $table->setRows($rows);
            $table->render();
        } else {
            $table = new Table($output);
            $headers = ['Metric'];
            foreach ($engines as $name) {
                $headers[] = $name;
            }
            $table->setHeaders($headers);
            $rows = [];
            foreach ($metrics as $m) {
                $row = [$m];
                foreach ($engines as $name) {
                    $d = $this->allResults[$name][$section][$m] ?? null;
                    $row[] = $d['display'] ?? '-';
                }
                $rows[] = $row;
            }
            $table->setRows($rows);
            $table->render();
        }
    }
}
