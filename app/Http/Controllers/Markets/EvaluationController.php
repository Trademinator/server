<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\ModelEvaluation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EvaluationController extends Controller
{
    public function index(Request $request, ModelEvaluation $evaluations): View
    {
        return view('markets.evaluations-index', [
            'models' => $evaluations->list($request->user()->isOwner(), $request->user()->getAuthIdentifier()),
            'ownerRoute' => $request->routeIs('owner.*'),
        ]);
    }

    public function show(Request $request, string $model, ModelEvaluation $evaluations): View
    {
        $report = $evaluations->report($model, $request->user()->isOwner(), $request->user()->getAuthIdentifier());
        $tournament = $evaluations->tournament((string) ($report['dataset_id'] ?? ''));
        return view('markets.evaluations-show', [
            'report' => $report,
            'tournament' => $tournament,
            'series' => $evaluations->series($report, $tournament),
            'model' => $model,
            'ownerRoute' => $request->routeIs('owner.*'),
        ]);
    }
}
