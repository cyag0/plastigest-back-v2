<?php

namespace App\Http\Controllers;

use App\Services\Replenishment\ReplenishmentPlanner;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sugerencias de reabastecimiento (traspasos, compras y producción) para lo
 * que queda de hoy y los días a cubrir. Ver ReplenishmentPlanner.
 */
class ReplenishmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_list')) {
            return response()->json(['message' => 'No tienes permiso para ver las sugerencias de reabastecimiento.'], 403);
        }

        $companyId = CurrentCompany::id();
        if (!$companyId) {
            return response()->json(['message' => 'Elige una empresa.'], 422);
        }

        $validated = $request->validate([
            'coverage_days' => 'nullable|integer|min:1|max:14',
        ]);

        $plan = (new ReplenishmentPlanner($companyId, now(), (int) ($validated['coverage_days'] ?? 1)))->plan();

        // Quien solo opera su sucursal ve lo que le toca: lo que le van a
        // mandar, lo que ella manda y lo que se compra o produce en ella.
        if (!CurrentWorker::canAccessAllLocations()) {
            $locationId = CurrentLocation::id();
            $mine = fn(array $group, string $key) => ($group[$key]['id'] ?? null) === $locationId;

            $plan['transfers'] = array_values(array_filter(
                $plan['transfers'],
                fn($group) => $mine($group, 'to') || $mine($group, 'from'),
            ));
            $plan['purchases'] = array_values(array_filter($plan['purchases'], fn($group) => $mine($group, 'location')));
            $plan['production'] = array_values(array_filter($plan['production'], fn($group) => $mine($group, 'location')));
            $plan['shortages'] = array_values(array_filter(
                $plan['shortages'],
                fn($item) => collect($item['needs'])->contains(fn($need) => $need['location']['id'] === $locationId),
            ));
        }

        return response()->json(['data' => $plan]);
    }
}
