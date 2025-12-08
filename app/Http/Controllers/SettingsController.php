<?php

namespace App\Http\Controllers;

use App\Models\Admin\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    /**
     * Get settings for a specific location
     */
    public function show(Request $request, $locationId)
    {
        $location = Location::findOrFail($locationId);

        // Ensure user has access to this location's company
        $user = $request->user();
        $hasAccess = $user->workers()
            ->where('company_id', $location->company_id)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this location',
            ], 403);
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
        $location = Location::findOrFail($locationId);

        // Ensure user has access to update this location
        $user = $request->user();
        $isAdmin = $user->workers()
            ->where('company_id', $location->company_id)
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['Admin', 'Manager', 'Administrador', 'Gerente']);
            })
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update settings',
            ], 403);
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
        $location = Location::findOrFail($locationId);

        // Ensure user has access to update this location
        $user = $request->user();
        $isAdmin = $user->workers()
            ->where('company_id', $location->company_id)
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['Admin', 'Manager', 'Administrador', 'Gerente']);
            })
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update settings',
            ], 403);
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
        $location = Location::findOrFail($locationId);

        // Ensure user has access to update this location
        $user = $request->user();
        $isAdmin = $user->workers()
            ->where('company_id', $location->company_id)
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['Admin', 'Manager', 'Administrador', 'Gerente']);
            })
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to reset settings',
            ], 403);
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
                'enable_whatsapp_orders' => true,
                'enable_pos_mode' => false,
            ],
        ];
    }
}
