<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\Admin\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El formulario de recordatorios cargaba a quién asignarlo con la lista de
 * usuarios de Administración (users_list): sin ese permiso no había a quién
 * asignar. GET reminders/users devolvía a todos los de la empresa (inactivos
 * y de otras sucursales, con su correo) y user_id aceptaba a cualquier
 * usuario existente, de cualquier empresa.
 */
class AccesosReminderAssigneesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const URL = '/api/auth/admin/reminders/users';

    private Location $otherBranch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->otherBranch = $this->createBranch(['name' => 'Pitillal']);
        $this->cashier = $this->createBranchUser($this->location, ['reminders_create', 'reminders_list']);
    }

    private function reminder(array $attributes = [])
    {
        return $this->actingInBranch($this->cashier)->postJson('/api/auth/admin/reminders', [
            'title' => 'Pagar la luz', 'type' => 'payment', 'reminder_date' => now()->addDays(3)->toDateString(),
            ...$attributes,
        ]);
    }

    public function test_the_form_gets_active_people_of_the_branch_without_users_list(): void
    {
        $coworker = $this->createBranchUser($this->location, ['sales_pos']);
        $inactive = $this->createBranchUser($this->location, ['sales_pos']);
        $inactive->update(['is_active' => false]);
        $elsewhere = $this->createBranchUser($this->otherBranch, ['sales_pos']);
        $regional = $this->createUserWithRole($this->otherBranch, ['sales_pos', 'locations_all']);
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $foreigner = $this->createUserWithRole($foreignBranch, ['sales_pos']);

        $rows = $this->actingInBranch($this->cashier)->getJson(self::URL)->assertOk()->json('data');
        $ids = collect($rows)->pluck('id');

        foreach ([$this->cashier, $coworker, $regional, $this->user] as $user) {
            $this->assertContains($user->id, $ids, $user->name);
        }
        foreach ([$inactive, $elsewhere, $foreigner] as $user) {
            $this->assertNotContains($user->id, $ids, $user->name);
        }
        $this->assertSame(['id', 'name'], array_keys($rows[0]), 'sin correo');
    }

    public function test_it_needs_some_reminders_permission(): void
    {
        $this->actingInBranch($this->createBranchUser($this->location, ['sales_pos']))->getJson(self::URL)->assertForbidden();

        // También con el permiso en el rol de otra sucursal, para quien entra
        // a todas (antes hasAnyPermission solo miraba el rol de la sucursal).
        $regional = $this->createUserWithRole($this->otherBranch, ['reminders_create', 'locations_all']);
        $this->actingInBranch($regional)->getJson(self::URL)->assertOk();
    }

    public function test_a_reminder_can_only_be_assigned_to_someone_from_that_list(): void
    {
        $coworker = $this->createBranchUser($this->location, ['sales_pos']);
        $elsewhere = $this->createBranchUser($this->otherBranch, ['sales_pos']);
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $foreigner = $this->createUserWithRole($foreignBranch, ['sales_pos']);

        foreach ([$elsewhere, $foreigner] as $user) {
            $this->reminder(['user_id' => $user->id])
                ->assertStatus(422)
                ->assertJsonPath('errors.user_id.0', 'Ese usuario no puede recibir recordatorios de esta sucursal.');
        }

        $this->reminder(['user_id' => $coworker->id])->assertCreated()->assertJsonPath('data.user_id', $coworker->id);
        $this->reminder()->assertCreated()->assertJsonPath('data.user_id', $this->cashier->id);
    }

    public function test_editing_keeps_the_current_assignee_even_if_no_longer_eligible(): void
    {
        $coworker = $this->createBranchUser($this->location, ['sales_pos']);
        $id = $this->reminder(['user_id' => $coworker->id])->assertCreated()->json('data.id');
        $coworker->update(['is_active' => false]);

        $editor = $this->createBranchUser($this->location, ['reminders_update', 'reminders_list']);
        $this->actingInBranch($editor)
            ->putJson("/api/auth/admin/reminders/{$id}", ['title' => 'Pagar la luz y el agua', 'user_id' => $coworker->id])
            ->assertOk();
        $this->assertSame($coworker->id, (int) DB::table('reminders')->where('id', $id)->value('user_id'));

        $elsewhere = $this->createBranchUser($this->otherBranch, ['sales_pos']);
        $this->actingInBranch($editor)
            ->putJson("/api/auth/admin/reminders/{$id}", ['user_id' => $elsewhere->id])
            ->assertStatus(422);
    }
}
