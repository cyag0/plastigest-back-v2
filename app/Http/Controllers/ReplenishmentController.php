<?php

namespace App\Http\Controllers;

use App\Services\Replenishment\ReplenishmentPlanner;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sugerencias de reabastecimiento (traspasos, compras y producción) para lo
 * que queda de hoy y los días a cubrir. Ver ReplenishmentPlanner.
 */
class ReplenishmentController extends Controller
{
    /** Minutos que se reutiliza un plan ya calculado (ver cachedPlan()). */
    private const CACHE_MINUTES = 5;

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

        $plan = $this->cachedPlan($companyId, (int) ($validated['coverage_days'] ?? 1));

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

    /**
     * El plan de la empresa, reutilizado unos minutos: calcularlo recorre el
     * libro mayor varias veces y la pantalla lo vuelve a pedir cada vez que
     * se enfoca.
     *
     * La llave lleva la empresa, los días a cubrir, el día y una huella de
     * las transferencias y compras de la empresa: en cuanto se crea o cambia
     * una (p. ej. la que se acaba de pedir desde estas sugerencias) el plan
     * se recalcula, para no sugerir dos veces lo mismo. Lo vendido en el
     * momento se refleja a más tardar en CACHE_MINUTES. El plan es de toda la
     * empresa y la sucursal se filtra después, por eso no va en la llave. Si
     * la caché falla, se calcula sin ella.
     */
    private function cachedPlan(int $companyId, int $coverageDays): array
    {
        $compute = fn() => (new ReplenishmentPlanner($companyId, now(), $coverageDays))->plan();

        try {
            $key = implode(':', [
                'replenishment-plan', $companyId, $coverageDays, now()->toDateString(), $this->documentsFingerprint($companyId),
            ]);
            $cached = Cache::get($key);
        } catch (\Throwable) {
            return $compute();
        }

        if (is_array($cached)) {
            return $cached;
        }

        $plan = $compute();

        try {
            Cache::put($key, $plan, now()->addMinutes(self::CACHE_MINUTES));
        } catch (\Throwable) {
            // Sin caché el plan sigue siendo válido.
        }

        return $plan;
    }

    /** Cuántas transferencias y compras hay y cuándo cambió la última. */
    private function documentsFingerprint(int $companyId): string
    {
        $parts = [];
        foreach (['inventory_transfers', 'purchases'] as $table) {
            $row = DB::table($table)
                ->where('company_id', $companyId)
                ->selectRaw('COUNT(*) as total, MAX(updated_at) as changed_at')
                ->first();
            $parts[] = ($row->total ?? 0) . '@' . ($row->changed_at ?? '');
        }

        return md5(implode('|', $parts));
    }
}
