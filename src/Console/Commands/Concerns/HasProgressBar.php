<?php

namespace Moaines\IllumiSearch\Console\Commands\Concerns;

use Symfony\Component\Console\Helper\ProgressBar;

trait HasProgressBar
{
    protected function startProgressBar(?ProgressBar &$pb, string $modelClass, int $total): void
    {
        $this->clearProgressBar($pb);
        $short = class_basename($modelClass);
        $this->line("  <fg=yellow>{$short}</>");
        $pb = $this->output->createProgressBar($total);
        $pb->setFormat('    %current%/%max% [%bar%] %elapsed:6s%');
        $pb->start();
    }

    protected function finishProgressBar(?ProgressBar &$pb): void
    {
        if ($pb === null) {
            return;
        }
        $pb->finish();
        $this->newLine(2);
        $pb = null;
    }

    protected function clearProgressBar(?ProgressBar $pb): void
    {
        if ($pb !== null) {
            $pb->clear();
        }
    }
}
