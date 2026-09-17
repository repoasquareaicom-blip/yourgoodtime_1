<?php

namespace App\Http\Controllers;

use App\Services\Prediction\PredictionExcelValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPredictionExcelValidationController extends Controller
{
    public function validateExcel(
        Request $request,
        PredictionExcelValidationService $validationService
    ): JsonResponse {
        $validated = $request->validate([
            'validator' => ['nullable', 'string', 'in:rule001_nakshatra_lagnam,rule002_past_workbooks'],
            'workbook' => ['nullable', 'string', 'max:160'],
        ]);

        $result = match ($validated['validator'] ?? 'rule001_nakshatra_lagnam') {
            'rule002_past_workbooks' => $validationService->validateRule002PastWorkbooks($validated['workbook'] ?? null),
            default => $validationService->validateNakshatraLagnam(),
        };

        return response()->json($result);
    }
}
