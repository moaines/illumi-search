<?php

namespace Moaines\IllumiSearch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Moaines\IllumiSearch\Http\Requests\SearchApiRequest;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Spellcheck;

class SearchApiController extends Controller
{
    public function __invoke(SearchApiRequest $request, Engine $engine): JsonResponse
    {
        $query = $request->input('q');
        $limit = $request->integer('limit', 10);
        $mode = $request->input('mode', 'advanced');
        $withSuggest = $request->boolean('suggest');

        $modelsInput = $request->input('models', '');
        $modelClasses = is_array($modelsInput)
            ? array_filter($modelsInput)
            : array_filter(explode(',', $modelsInput));

        if (empty($modelClasses)) {
            $modelClasses = $engine->getIndexedModelClasses();
        }

        $results = $engine->search($query, $modelClasses, $limit, 0, $mode, withSnippets: true);

        $suggestions = [];

        if ($withSuggest && empty($results) && mb_strlen($query) > 2) {
            $suggestions = app(Spellcheck::class)
                ->suggest($query, $modelClasses)
                ->values()
                ->toArray();
        }

        return response()->json([
            'results'     => $results,
            'total'       => count($results),
            'suggestions' => $suggestions,
        ]);
    }
}
