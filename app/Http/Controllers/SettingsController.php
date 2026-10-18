<?php

namespace App\Http\Controllers;

use App\Models\Admin\Location;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    /**
     * La sucursal debe pertenecer a la empresa del contexto y el usuario debe
     * tener el permiso indicado. Antes esto usaba $user->workers(), un método
     * inexistente que hacía que TODA la pantalla de configuración respondiera 500.
     *
     * Quien no tiene acceso a todas las sucursales solo ve y cambia la de la
     * sucursal actual: sus permisos son los del rol de esa sucursal, y antes
     * podía cambiar la configuración de cualquier otra mandando su id.
     *
     * @return Location|\Illuminate\Http\JsonResponse
     */
    private function authorizeLocation($locationId, string $permission)
    {
        $location = Location::find($locationId);

        if (!$location || (int) $location->company_id !== (int) CurrentCompany::id()) {
            return response()->json(['success' => false, 'message' => 'Sucursal no encontrada'], 404);
        }

        if (!CurrentWorker::canAccessAllLocations() && (int) $location->id !== (int) CurrentLocation::id()) {
            return response()->json(['success' => false, 'message' => 'Sucursal no encontrada'], 404);
        }

        if (!CurrentWorker::hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $location;
    }

    /**
     * Get settings for a specific location
     */
    public function show(Request $request, $locationId)
    {
        $location = $this->authorizeLocation($locationId, 'settings_read');
        if (!$location instanceof Location) {
            return $location; // respuesta 403/404
        }

        // Parse settings if they exist
        $settings = $location->settings;

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        // Ensure default structure exists
        $defaultSettings = $this->getDefaultSettings();
        $settings = array_merge($defaultSettings, $settings ?? []);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /**
     * Update settings for a specific location
     */
    public function update(Request $request, $locationId)
    {
        $location = $this->authorizeLocation($locationId, 'settings_update');
        if (!$location instanceof Location) {
            return $location; // respuesta 403/404
        }

        // Get settings from request (could be in 'settings' key or directly in body)
        $newSettings = $request->input('settings', $request->all());

        // Merge with existing settings to avoid overwriting other sections
        $currentSettings = is_string($location->settings)
            ? json_decode($location->settings, true)
            : ($location->settings ?? []);

        $mergedSettings = array_merge($currentSettings, $newSettings);

        // Update location settings
        $location->settings = $mergedSettings;
        $location->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => $mergedSettings,
        ]);
    }

    /**
     * Update a specific section of settings
     */
    public function updateSection(Request $request, $locationId, $section)
    {
        $location = $this->authorizeLocation($locationId, 'settings_update');
        if (!$location instanceof Location) {
            return $location; // respuesta 403/404
        }

        $validSections = ['notifications', 'working_hours', 'auto_tasks', 'limits', 'features'];

        if (!in_array($section, $validSections)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid settings section',
            ], 400);
        }

        // Get current settings
        $currentSettings = is_string($location->settings)
            ? json_decode($location->settings, true)
            : ($location->settings ?? []);

        // Update only the specified section
        $currentSettings[$section] = $request->all();

        // Save updated settings
        $location->settings = $currentSettings;
        $location->save();

        return response()->json([
            'success' => true,
            'message' => ucfirst($section) . ' settings updated successfully',
            'data' => $currentSettings,
        ]);
    }

    /**
     * Reset settings to default for a location
     */
    public function reset(Request $request, $locationId)
    {
        $location = $this->authorizeLocation($locationId, 'settings_update');
        if (!$location instanceof Location) {
            return $location; // respuesta 403/404
        }

        $location->settings = $this->getDefaultSettings();
        $location->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings reset to default',
            'data' => $location->settings,
        ]);
    }

    /**
     * Get default settings structure
     */
    private function getDefaultSettings(): array
    {
        return [
            'notifications' => [
                'low_stock' => [
                    'enabled' => true,
                    'users' => [],
                ],
                'purchase_confirmed' => [
                    'enabled' => true,
                    'users' => [],
                ],
                'transfer_received' => [
                    'enabled' => true,
                    'users' => [],
                ],
                'inventory_discrepancies' => [
                    'enabled' => true,
                    'users' => [],
                ],
                'adjustment_created' => [
                    'enabled' => true,
                    'users' => [],
                ],
            ],
            'working_hours' => [
                'monday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
                'tuesday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
                'wednesday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
                'thursday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
                'friday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
                'saturday' => ['start' => '09:00', 'end' => '14:00', 'enabled' => true],
                'sunday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => false],
            ],
            'auto_tasks' => [
                'inventory_count_enabled' => false,
                'inventory_count_frequency' => 'monthly',
                'inventory_count_day' => 1,
                'sales_report_enabled' => false,
                'sales_report_frequency' => 'daily',
            ],
            'limits' => [
                'max_discount_percentage' => 10,
                'require_approval_above' => 10000,
                'low_stock_threshold' => 10,
            ],
            'features' => [
                'enable_barcode_scanner' => true,
                'enable_whatsapp_orders' => \App\Services\WhatsAppService::isEnabled(),
                'enable_pos_mode' => false,
            ],
        ];
    }
}
