<?php

namespace App\Http\Controllers;

use App\Http\Requests\DetectedSourceRequest;
use App\Http\Requests\DetectSourceRequest;
use App\Services\Feed\Detection\SourceDetector;
use App\Services\Feed\Detection\SourcePreviewService;
use Illuminate\Http\JsonResponse;

/**
 * Détection et prévisualisation d'une URL (M8.3) — JSON, lecture
 * seule : aucune source créée ni modifiée. Consommé par l'UI M8.5.
 */
class SourceDetectionController extends Controller
{
    public function detect(DetectSourceRequest $request, SourceDetector $detector): JsonResponse
    {
        return response()->json($detector->detect((string) $request->validated('url'))->toArray());
    }

    public function preview(DetectedSourceRequest $request, SourcePreviewService $previews): JsonResponse
    {
        return response()->json(
            $previews->preview((string) $request->validated('url'), $request->htmlConfig()),
        );
    }
}
