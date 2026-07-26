<?php

namespace Moaines\IllumiSearch\Concerns;

use Illuminate\Support\Facades\DB;

trait HasMaxDocuments
{
    /**
     * Prune excess documents per model class, keeping only the N most recent
     * (highest model_id) documents for each model_type.
     *
     * Called automatically after insertBatch().
     */
    public function pruneExcessDocuments(string $modelClass): void
    {
        $max = $this->illumiConfig->maxDocumentsPerModel();
        if ($max <= 0) {
            return;
        }

        $table = $this->table(self::TABLE);
        $connection = $this->connection;

        // Find the cutoff model_id: the N-th highest for this model_type
        $cutoff = DB::connection($connection)->selectOne(
            "SELECT model_id FROM {$table}
             WHERE model_type = ?
             ORDER BY model_id DESC
             LIMIT 1 OFFSET ?",
            [$modelClass, $max - 1]
        );

        if ($cutoff === null) {
            return; // Fewer than max documents — nothing to prune
        }

        DB::connection($connection)->statement(
            "DELETE FROM {$table}
             WHERE model_type = ?
               AND model_id < ?",
            [$modelClass, $cutoff->model_id]
        );
    }
}
