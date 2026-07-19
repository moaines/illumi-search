<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Concerns\HasQueryTerms;

class SnippetService
{
    use HasQueryTerms;

    private array $defaultTextColumns = ['body', 'content', 'description', 'text', 'excerpt'];

    public function enrich(array $results, string $query): array
    {
        $grouped = [];
        $order = [];

        foreach ($results as $i => $r) {
            $grouped[$r['modelClass']][] = $r;
            $order[] = ['modelClass' => $r['modelClass'], 'index' => count($grouped[$r['modelClass']]) - 1];
        }

        $searchTerms = $this->extractSearchTerms($query);

        foreach ($grouped as $modelClass => &$entries) {
            $ids = array_column($entries, 'modelId');

            if (! class_exists($modelClass) || empty($ids)) {
                continue;
            }

            try {
                $instance = new $modelClass;
                $keyName = $instance->getKeyName();

                $snippetCols = $this->resolveSnippetColumns($instance);
                $textColumns = $snippetCols ?? $this->defaultTextColumns;

                $selectCols = [$keyName];
                $relationCols = [];

                foreach ($textColumns as $col) {
                    if (str_contains($col, '.')) {
                        $relName = explode('.', $col)[0];
                        if (! in_array($relName, $relationCols, true)) {
                            $relationCols[] = $relName;
                        }
                    } elseif (Schema::hasColumn($instance->getTable(), $col)) {
                        $selectCols[] = $col;
                    }
                }

                $query = $modelClass::whereIn($keyName, $ids);

                if (count($selectCols) > 1) {
                    $query->select($selectCols);
                }

                $models = $query->get()->keyBy->getKey();

                if (! empty($relationCols)) {
                    $models->load(array_unique($relationCols));
                }

                foreach ($entries as &$entry) {
                    $model = $models[$entry['modelId']] ?? null;
                    if ($model === null) {
                        continue;
                    }

                    $entry['eloquentModel'] = $model;
                    $entry['title'] = $model->title ?? $entry['title'];
                    $entry['summary'] = $this->extractSnippet($model, $searchTerms, $snippetCols);
                }
            } catch (\Exception) {
                continue;
            }
        }

        $enriched = [];
        foreach ($results as $i => $r) {
            $mc = $r['modelClass'];
            $gi = $order[$i]['index'];
            $enriched[] = $grouped[$mc][$gi];
        }

        return $enriched;
    }

    public function resolveSnippetColumns(Model $model): ?array
    {
        if (! method_exists($model, 'getSearchableColumns')) {
            return null;
        }

        $raw = $model->getSearchableColumns();
        if (empty($raw)) {
            return null;
        }

        if (! method_exists($model, 'normalizeSearchable')) {
            return null;
        }

        $searchable = $model->normalizeSearchable();
        $allowed = [];

        foreach ($searchable as $column => $config) {
            $snippetEnabled = $config['snippet'] ?? true;
            if ($snippetEnabled) {
                $allowed[] = $column;
            }
        }

        return ! empty($allowed) ? $allowed : null;
    }

    private function extractSnippet(Model $model, array $searchTerms, ?array $snippetColumns = null): ?string
    {
        $textColumns = $snippetColumns ?? $this->defaultTextColumns;
        $sourceText = null;
        $bestPos = null;
        $bestTerm = '';

        foreach ($textColumns as $col) {
            $value = $this->snippetColumnValue($model, $col);

            if (! is_string($value) || strlen($value) <= 50) {
                continue;
            }

            $lower = mb_strtolower(strip_tags($value));

            foreach ($searchTerms as $term) {
                $termLower = mb_strtolower($term);
                $pos = mb_strpos($lower, $termLower);
                if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                    $bestPos = $pos;
                    $bestTerm = $term;
                    $sourceText = $value;
                }
            }
        }

        if ($sourceText === null) {
            foreach ($textColumns as $col) {
                $value = $this->snippetColumnValue($model, $col);
                if (is_string($value) && strlen($value) > 50) {
                    $sourceText = $value;
                    $bestPos = 0;
                    break;
                }
            }

            if ($sourceText === null) {
                return null;
            }
        }

        $windowSize = 120;
        $snippetStart = max(0, $bestPos - 60);
        $snippetLen = min(mb_strlen($sourceText), $windowSize);
        $snippet = mb_substr(strip_tags($sourceText), $snippetStart, $snippetLen);

        if ($snippetStart > 0) {
            $snippet = '…'.$snippet;
        }
        if ($snippetStart + $snippetLen < mb_strlen(strip_tags($sourceText)) - 1) {
            $snippet .= '…';
        }

        foreach ($searchTerms as $term) {
            if (empty(trim($term))) {
                continue;
            }
            $snippet = preg_replace(
                '/'.preg_quote($term, '/').'/iu',
                '<mark>$0</mark>',
                $snippet,
            );
        }

        return $snippet;
    }

    private function snippetColumnValue(Model $model, string $col): string
    {
        if (str_contains($col, '.') && method_exists($model, 'resolveSearchValue')) {
            return $model->resolveSearchValue($col);
        }

        return $model->{$col} ?? '';
    }

    private function extractSearchTerms(string $query): array
    {
        return $this->extractQueryTerms($query);
    }
}
