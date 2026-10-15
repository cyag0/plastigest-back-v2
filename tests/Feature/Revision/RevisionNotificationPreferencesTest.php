<?php

namespace Tests\Feature\Revision;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * notifications_manage (en la plantilla "Encargado") dejaba a un encargado
 * apagar o redirigir para TODA la empresa avisos como las mermas: sin
 * scope=location se escribe la configuración de la empresa. Ahora esa solo la
 * cambia quien tiene acceso a todas las sucursales; el encargado edita la de
 * su sucursal. Los destinatarios tienen que ser usuarios activos de la
 * empresa.
 */
class RevisionNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MANAGER = ['notifications_read', 'notifications_list', 'notifications_manage', 'inventory_manage'];

    private const URL = '/api/auth/admin/notification-preferences';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->manager = $this->createBranchUser($this->location, self::MANAGER);
    }

    private function companyRow(string $eventType): ?NotificationPreference
    {
        return NotificationPreference::where('company_id', $this->company->id)
            ->whereNull('location_id')->where('event_type', $eventType)->first();
    }

    public function test_a_branch_manager_cannot_change_the_company_configuration(): void
    {
        $this->actingInBranch($this->manager)
            ->patchJson(self::URL . '/inventory_adjustment', ['is_active' => false, 'channel_email' => false, 'channel_push' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'company_scope_forbidden');
        $this->actingInBranch($this->manager)
            ->patchJson(self::URL . '/inventory_adjustment', ['allowed_user_ids' => [$this->manager->id]])
            ->assertForbidden();

        $this->assertNull($this->companyRow('inventory_adjustment'));
    }

    public function test_a_branch_manager_cannot_restore_or_reset_the_company_configuration(): void
    {
        // El dueño (acceso a todas las sucursales) personalizó el aviso.
        $this->actingInBranch($this->user)
            ->patchJson(self::URL . '/inventory_adjustment', ['channel_email' => true])
            ->assertOk();

        $this->actingInBranch($this->manager)->deleteJson(self::URL . '/inventory_adjustment')->assertForbidden();
        $this->actingInBranch($this->manager)->postJson(self::URL . '/reset')->assertForbidden();

        $this->assertTrue((bool) $this->companyRow('inventory_adjustment')?->channel_email);
    }

    public function test_a_branch_manager_customizes_their_own_branch(): void
    {
        $this->actingInBranch($this->manager)
            ->patchJson(self::URL . '/low_stock?scope=location', ['channel_push' => false])
            ->assertOk();

        $row = NotificationPreference::where('company_id', $this->company->id)
            ->where('location_id', $this->location->id)->where('event_type', 'low_stock')->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->channel_push);
        $this->assertNull($this->companyRow('low_stock'));

        $this->actingInBranch($this->manager)->deleteJson(self::URL . '/low_stock?scope=location')->assertOk();
        $this->actingInBranch($this->manager)->postJson(self::URL . '/reset?scope=location')->assertOk();
    }

    public function test_recipients_must_be_active_users_of_the_company(): void
    {
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $foreigner = $this->createUserWithRole($foreignBranch, ['inventory_manage']);
        $inactive = $this->createUserWithRole($this->location, ['inventory_manage']);
        $inactive->forceFill(['is_active' => false])->save();
        $coworker = $this->createUserWithRole($this->location, ['inventory_manage']);

        foreach ([$foreigner, $inactive] as $user) {
            $this->actingInBranch($this->user)
                ->patchJson(self::URL . '/low_stock', ['allowed_user_ids' => [$user->id]])
                ->assertStatus(422);
        }
        $this->assertNull($this->companyRow('low_stock'));

        $this->actingInBranch($this->user)
            ->patchJson(self::URL . '/low_stock', ['allowed_user_ids' => [$coworker->id]])
            ->assertOk();
        $this->assertSame([$coworker->id], $this->companyRow('low_stock')->allowed_user_ids);
    }

    public function test_a_branch_manager_only_sees_eligible_recipients_of_their_branch(): void
    {
        $here = $this->createUserWithRole($this->location, ['inventory_manage']);
        $there = $this->createUserWithRole($this->createBranch(), ['inventory_manage']);

        $ids = collect($this->actingInBranch($this->manager)
            ->getJson(self::URL . '/low_stock/eligible-users')
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($here->id));
        $this->assertFalse($ids->contains($there->id), 'vio destinatarios de otra sucursal');
    }
}
