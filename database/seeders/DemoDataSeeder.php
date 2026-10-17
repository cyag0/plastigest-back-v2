<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Datos de demostración: varias semanas de operación de Cocos Francisco.
 *
 * Todo pasa por la API de la app (compras, producción, transferencias, turnos
 * de caja, ventas, gastos, ajustes) con el reloj movido a cada día, así que el
 * stock, el libro mayor, la caja y los turnos cuadran como en la operación real.
 *
 *   sail artisan db:seed --class=DemoDataSeeder
 *   DEMO_DAYS=20 sail artisan db:seed --class=DemoDataSeeder
 *
 * Solo corre en local y una vez (si ya existen los usuarios demo, no hace nada).
 * No envía nada hacia afuera: WhatsApp, correo, push y la cola quedan apagados
 * mientras corre.
 */
class DemoDataSeeder extends Seeder
{
    private const EMAIL_DOMAIN = 'cocos-francisco.com';

    /**
     * Contraseña de los usuarios demo: DEMO_PASSWORD o, si no está definida,
     * una al azar que se muestra al terminar. Antes iba escrita aquí y el
     * repositorio es público: si una base con datos demo llegara a usarse en
     * serio, cualquiera entraría como encargado de sucursal.
     */
    private string $password;

    private Company $company;
    private Location $matriz;
    /** @var Location[] */
    private array $branches = [];
    private User $admin;
    /** @var array<int, User[]> cajeros por sucursal */
    private array $cashiers = [];
    /** @var array<int, User> encargado por sucursal */
    private array $managers = [];
    /** @var array<int, int[]> cajas por sucursal */
    private array $registers = [];
    /** @var array<int, string> token por usuario */
    private array $tokens = [];
    /** @var array<string, object> productos por código */
    private array $products = [];
    /** @var array<string, int> procesos (fórmulas) por nombre */
    private array $formulas = [];
    /** @var array<string, array{content: float, materials: array<string, float>}> receta de envasado por código */
    private array $recipes = [];
    /** @var array<string, int> proveedores por nombre */
    private array $suppliers = [];
    private array $customerIds = [];
    private Kernel $kernel;
    private string $evidencePath;
    private array $stats = [];

    public function run(): void
    {
        if (!app()->environment('local')) {
            $this->command->error('DemoDataSeeder solo corre en local.');
            return;
        }
        if (User::where('email', 'like', 'cajero.%@' . self::EMAIL_DOMAIN)->exists()) {
            $this->command->warn('Los datos demo ya existen (hay usuarios cajero.*). No se hace nada.');
            return;
        }

        $this->password = (string) env('DEMO_PASSWORD', '') ?: Str::password(16, symbols: false);
        $this->isolate();
        $this->kernel = app(Kernel::class);

        // Los seeders corren con los modelos sin proteger (unguarded); la API
        // tiene que comportarse como en producción, con $fillable activo.
        Model::reguard();
        mt_srand(2026);

        $days = max(3, (int) env('DEMO_DAYS', 45));
        // Termina ayer: hoy queda libre para usar la app.
        $start = Carbon::today()->subDays($days + 1);

        $this->at($start, 8);
        $this->loadContext();
        $this->createTeam();
        $this->createRegisters();
        $this->createCustomers();

        // Día 0: surtir la matriz, producir y repartir a las sucursales.
        $this->purchases($start, 0, true);
        $this->production($start, 0);
        foreach ($this->branches as $branch) {
            $this->restock($branch, $start);
        }

        for ($d = 1; $d <= $days; $d++) {
            $day = $start->copy()->addDays($d);
            $last = $d === $days;

            $this->purchases($day, $d, false, $last);
            $this->production($day, $d);
            foreach ($this->branches as $i => $branch) {
                if (($d + $i) % 2 === 0) {
                    $this->restock($branch, $day);
                }
            }
            foreach ([$this->matriz, ...$this->branches] as $location) {
                $this->salesDay($location, $day);
            }
            $this->expenses($day, $d);
            if ($d % 7 === 3) {
                $this->adjustments($day);
            }

            $this->command->getOutput()->write(sprintf("\r  Día %d/%d (%s) · %d ventas", $d, $days, $day->toDateString(), $this->stats['ventas'] ?? 0));
        }

        $this->command->newLine();
        Carbon::setTestNow();
        $this->cleanup();

        foreach ($this->stats as $what => $count) {
            $this->command->line(sprintf('  %-22s %d', $what, $count));
        }
        $this->command->info('Usuarios demo: cajero.<sucursal>@' . self::EMAIL_DOMAIN . ' / encargado.<sucursal>@' . self::EMAIL_DOMAIN . '  contraseña ' . $this->password);
    }

    // ─── Preparación ─────────────────────────────────────────────────────────

    /** Nada sale de la máquina mientras corre el seeder. */
    private function isolate(): void
    {
        config([
            'services.whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            // Los tokens se crean el primer día simulado; con la caducidad de
            // Sanctum (30 días) vencerían a mitad de la historia (DEMO_DAYS=45).
            'sanctum.expiration' => null,
        ]);
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function loadContext(): void
    {
        $this->company = Company::where('name', 'Cocos Francisco')->firstOrFail();
        $locations = Location::where('company_id', $this->company->id)->orderBy('id')->get();
        $this->matriz = $locations->first(fn($l) => str_contains($l->name, 'Matriz')) ?? $locations->first();
        $this->branches = $locations->reject(fn($l) => $l->id === $this->matriz->id)->values()->all();
        $this->admin = User::where('email', 'admin@' . self::EMAIL_DOMAIN)->firstOrFail();
        $this->tokens[$this->admin->id] = $this->admin->createToken('demo-seeder')->plainTextToken;

        foreach (DB::table('products')->where('company_id', $this->company->id)->get() as $p) {
            $this->products[$p->code] = $p;
        }
        $this->formulas = DB::table('formulas')->where('company_id', $this->company->id)->pluck('id', 'name')->all();
        $this->suppliers = DB::table('suppliers')->where('company_id', $this->company->id)->pluck('id', 'name')->all();
        $codes = array_flip(array_map(fn($p) => $p->id, $this->products));
        foreach ($this->products as $code => $p) {
            if ($p->requires_packaging && $p->packaging_source_product_id) {
                $this->recipes[$code] = [
                    'bulk' => $codes[$p->packaging_source_product_id] ?? null,
                    'content' => (float) $p->packaging_source_quantity,
                    'materials' => DB::table('product_packaging_materials')->where('product_id', $p->id)
                        ->pluck('quantity', 'material_product_id')
                        ->mapWithKeys(fn($q, $id) => [$codes[$id] => (float) $q])
                        ->all(),
                ];
            }
        }

        // Todas las sucursales manejan todo el catálogo.
        DB::table('product_location')
            ->whereIn('location_id', $locations->pluck('id'))
            ->update(['active' => true]);

        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 90, 130, 60));
        $this->evidencePath = tempnam(sys_get_temp_dir(), 'demo') . '.jpg';
        imagejpeg($img, $this->evidencePath);
        imagedestroy($img);
    }

    private function createTeam(): void
    {
        $all = DB::table('permissions')->pluck('id', 'name');
        $cashierPerms = ['sales_pos', 'sales_list', 'sales_read', 'sales_create', 'customers_list', 'customers_read', 'customers_create',
            'products_list', 'products_read', 'inventory_list', 'inventory_read', 'cash_registers_list', 'dashboard_view'];
        $managerPerms = $all->keys()->reject(fn($p) => in_array($p, [
            'system_admin', 'companies_create', 'companies_delete', 'companies_manage', 'roles_create', 'roles_delete', 'roles_update',
            'locations_create', 'locations_delete', 'locations_all', 'users_delete', 'settings_manage',
        ]))->all();

        $cashierRole = $this->role('Cajero', $cashierPerms, $all);
        $managerRole = $this->role('Encargado de sucursal', $managerPerms, $all);

        foreach ([$this->matriz, ...$this->branches] as $location) {
            $slug = $this->slug($location);
            $count = $location->id === $this->matriz->id ? 2 : 1;
            for ($i = 1; $i <= $count; $i++) {
                $suffix = $count > 1 ? $i : '';
                $this->cashiers[$location->id][] = $this->user("cajero.{$slug}{$suffix}", 'Cajero ' . $this->shortName($location) . ($suffix ? " {$suffix}" : ''), $location, $cashierRole);
            }
            $this->managers[$location->id] = $this->user("encargado.{$slug}", 'Encargado ' . $this->shortName($location), $location, $managerRole);
        }
        $this->stats['usuarios'] = count($this->managers) + array_sum(array_map('count', $this->cashiers));
    }

    private function role(string $name, array $permissions, $all): int
    {
        $id = DB::table('roles')->insertGetId([
            'name' => $name,
            'company_id' => $this->company->id,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            collect($permissions)->filter(fn($p) => isset($all[$p]))->map(fn($p) => ['role_id' => $id, 'permission_id' => $all[$p]])->values()->all()
        );

        return $id;
    }

    private function user(string $local, string $name, Location $location, int $roleId): User
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$local}@" . self::EMAIL_DOMAIN,
            'password' => Hash::make($this->password),
        ]);
        DB::table('user_location_roles')->insert(['user_id' => $user->id, 'location_id' => $location->id, 'role_id' => $roleId]);
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $this->company->id]);
        $this->tokens[$user->id] = $user->createToken('demo-seeder')->plainTextToken;

        return $user;
    }

    private function createRegisters(): void
    {
        foreach ([$this->matriz, ...$this->branches] as $location) {
            // Las cajas con un turno abierto (de alguien más) no se tocan.
            $all = DB::table('cash_registers')->where('location_id', $location->id)->where('is_active', true)->pluck('id');
            $busy = DB::table('cash_register_sessions')->where('status', 'open')->pluck('cash_register_id');
            $existing = $all->diff($busy)->values()->all();
            $needed = count($this->cashiers[$location->id]);
            for ($n = $all->count() + 1; count($existing) < $needed; $n++) {
                $existing[] = $this->api($this->admin, $location, 'POST', 'cash-registers', [
                    'name' => "Caja {$n}",
                    'code' => strtoupper(substr($this->slug($location), 0, 6)) . "-{$n}",
                    'location_id' => $location->id,
                    'is_active' => true,
                ])['data']['id'];
            }
            $this->registers[$location->id] = $existing;
        }
    }

    private function createCustomers(): void
    {
        $names = ['Hotel Playa Sol', 'Restaurante La Palapa', 'María Fernanda López', 'José Luis Ramírez', 'Abarrotes Doña Lupe',
            'Cafetería El Malecón', 'Ana Karen Díaz', 'Mariscos El Güero', 'Carlos Hernández', 'Fonda Doña Chayo',
            'Club Deportivo Vallarta', 'Laura Méndez', 'Tienda Naturista Vida', 'Roberto Sánchez', 'Escuela Primaria Juárez'];
        foreach ($names as $i => $name) {
            $this->customerIds[] = $this->api($this->admin, $this->matriz, 'POST', 'customers', [
                'company_id' => $this->company->id,
                'name' => $name,
                'phone' => '322' . str_pad((string) (1000000 + $i * 7919), 7, '0', STR_PAD_LEFT),
                'email' => 'cliente' . ($i + 1) . '@example.com',
                'is_active' => true,
            ])['data']['id'];
        }
        $this->stats['clientes'] = count($this->customerIds);
    }

    // ─── Compras (matriz) ────────────────────────────────────────────────────

    private function purchases(Carbon $day, int $d, bool $initial, bool $last = false): void
    {
        $plans = [];
        // Los cocos llegan cada dos días: la producción gasta unos 250 al día.
        if ($initial || $d % 2 === 0) {
            // Se pagan por transferencia: la caja de la matriz no alcanza para los cocos.
            $plans[] = ['Huerteros', 'transfer', false, ['COC-ENT-001' => [$initial ? 1000 : mt_rand(60, 70) * 10, 15]]];
            // La savia de la palma para la tuba, también de los huerteros.
            $plans[] = ['Huerteros', 'cash', true, ['ING-SAVIA-L' => [80, 12]]];
        }
        if ($initial || $d % 7 === 1) {
            $plans[] = ['TESTUS PET SOLUTIONS', 'transfer', false, ['PET-BOT-1LT' => [800, 2.23], 'PET-BOT-500T' => [500, 1.6], 'PET-TAP-R28' => [1300, 0.27]]];
            $plans[] = ['MAZAPLASTICOS', 'transfer', false, ['PET-GAL-4L' => [90, 6.2], 'PET-TAP-GAL' => [90, 0.27]]];
            $plans[] = ["Sam's Club", 'card', false, ['ING-AZU-KG' => [60, 28], 'ING-NUEZ-KG' => [10, 260], 'ING-LCL-LATA' => [24, 41.88]]];
        }
        if ($initial || $d % 7 === 4) {
            $plans[] = ['Colima Tropical', 'transfer', false, ['DER-ACN-180' => [30, 45], 'DER-ACN-1L' => [12, 180], 'DER-ACC-500' => [16, 50], 'DER-ACC-1L' => [10, 200]]];
            $plans[] = ['OSMAR ROMPOPE TALPA', 'cash', true, ['DER-RP-001' => [24, 70]]];
        }
        if ($initial || $d % 14 === 6) {
            $plans[] = ["Sam's Club", 'card', false, ['LIM-JAB-001' => [2, 400], 'LIM-CLO-001' => [3, 400], 'ING-SAL-001' => [2, 106.39]]];
        }

        foreach ($plans as $i => [$supplierName, $method, $fromCash, $items]) {
            $this->at($day, 8, 10 + $i * 7);
            $manager = $this->managers[$this->matriz->id];
            $purchase = $this->api($manager, $this->matriz, 'POST', 'purchases-v2/upsert-draft', [
                'supplier_id' => $this->suppliers[$supplierName],
                'payment_method' => $method,
                'paid_from_cash' => $fromCash,
                'items' => collect($items)->map(fn($qp, $code) => [
                    'id' => uniqid('tmp'),
                    'product_id' => $this->products[$code]->id,
                    'unit_id' => $this->products[$code]->unit_id,
                    'quantity' => $qp[0],
                    'price' => $qp[1],
                ])->values()->all(),
            ])['data'];
            $id = $purchase['purchase_id'];

            // De vez en cuando se cancela un pedido; el último día quedan pedidos en camino.
            if (!$initial && mt_rand(1, 40) === 1) {
                $this->api($manager, $this->matriz, 'POST', "purchases-v2/{$id}/confirm", ['expected_delivery_date' => $day->toDateString()]);
                $this->api($manager, $this->matriz, 'POST', "purchases-v2/{$id}/cancel", ['reason' => 'El proveedor no tenía existencia']);
                $this->count('compras canceladas');
                continue;
            }
            $this->api($manager, $this->matriz, 'POST', "purchases-v2/{$id}/confirm", ['expected_delivery_date' => $day->toDateString()]);
            if ($last && $i > 0) {
                $this->count('compras pendientes');
                continue;
            }
            $this->api($manager, $this->matriz, 'POST', "purchases-v2/{$id}/mark-in-transit");

            $this->at($day, 9, 30 + $i * 5);
            $details = DB::table('purchase_details')->where('purchase_id', $id)->get(['id', 'quantity']);
            $this->api($manager, $this->matriz, 'POST', "purchases-v2/{$id}/receive", [
                'details' => $details->map(fn($det) => [
                    'id' => $det->id,
                    // A veces llega incompleto (queda la diferencia por resolver).
                    'quantity_received' => mt_rand(1, 25) === 1 ? floor($det->quantity * 0.9) : (float) $det->quantity,
                ])->all(),
            ]);
            $this->count('compras');
        }
    }

    // ─── Producción (matriz) ─────────────────────────────────────────────────

    /**
     * Un día de planta, como se registra en la app: se abren los cocos y se
     * envasa el agua en el mismo registro; horchata y tuba igual; los dulces
     * cada dos días; el rallado cada tres, y se envasa al día siguiente
     * (envasado aparte). En la tarde se envasa el agua que quedó en la mañana.
     */
    private function production(Carbon $day, int $d): void
    {
        $scale = fn() => mt_rand(85, 115) / 100;

        // Abrir cocos: sale agua y pulpa; casi toda el agua se envasa ya.
        $this->at($day, 6, 30);
        $cocos = (int) min($this->stock($this->matriz, 'COC-ENT-001') - 150, mt_rand(200, 240));
        if ($cocos >= 50) {
            $agua = round($cocos * mt_rand(55, 65) / 100, 1);
            $pulpa = round($cocos * mt_rand(13, 17) / 100, 1);
            // A veces se tira un poco del agua (no se puede envasar).
            $spilled = mt_rand(1, 5) === 1 ? round(mt_rand(5, 20) / 10, 1) : 0;
            $wastes = $spilled > 0 ? ['GRA-AGUA' => [$spilled, 'spill', 'Se derramó al envasar']] : [];
            $packagings = $this->fitPackaging([
                'BEB-AC-1L' => (int) round(45 * $scale()), 'BEB-AC-500' => (int) round(35 * $scale()),
                'BEB-AC-GAL' => (int) round(6 * $scale()), 'BEB-VAC-1L' => 12, 'BEB-VAC-500' => 12,
            ], $agua - $spilled);
            $this->registerProduction('Abrir cocos', ['COC-ENT-001' => $cocos], ['GRA-AGUA' => $agua, 'GRA-PULPA' => $pulpa], $packagings, $wastes);
        }

        // Cocos para tomar en la sucursal.
        $this->at($day, 6, 50);
        $paraTomar = (int) min($this->stock($this->matriz, 'COC-ENT-001') - 150, mt_rand(20, 30));
        if ($paraTomar > 0) {
            $this->registerProduction('Coco para tomar', ['COC-ENT-001' => $paraTomar], ['BEB-CT-001' => $paraTomar]);
        }

        // Horchata con azúcar todos los días; sin azúcar, un día sí y uno no.
        $this->at($day, 7, 10);
        $sinAzucar = $d % 2 === 1;
        $pulpaHorchata = 6 + ($sinAzucar ? 2.5 : 0);
        if ($this->stock($this->matriz, 'GRA-PULPA') >= $pulpaHorchata && $this->stock($this->matriz, 'ING-AZU-KG') >= 3) {
            $horchata = round(24 * $scale(), 1);
            $outputs = ['GRA-HORCH' => $horchata];
            $targets = ['BEB-HC-1L' => 12, 'BEB-HC-500' => 12, 'BEB-HC-GAL' => 1];
            if ($sinAzucar) {
                $outputs['GRA-HORCH-SA'] = round(10 * $scale(), 1);
            }
            // Las dos horchatas usan las mismas botellas y tapas: un solo presupuesto.
            $materials = [];
            $packagings = $this->fitPackaging($targets, $horchata, $materials);
            if ($sinAzucar) {
                $packagings += $this->fitPackaging(['BEB-HSA-1L' => 6, 'BEB-HSA-500' => 6], $outputs['GRA-HORCH-SA'], $materials);
            }
            $this->registerProduction('Horchata', ['GRA-PULPA' => $pulpaHorchata, 'ING-AZU-KG' => round(2.4 * $scale(), 1)], $outputs, $packagings);
        }

        // Tuba: la savia de la palma, colada, y se envasa ya.
        $this->at($day, 7, 30);
        $savia = min($this->stock($this->matriz, 'ING-SAVIA-L'), 35);
        if ($savia >= 10) {
            $tuba = round($savia * mt_rand(92, 97) / 100, 1);
            $packagings = $this->fitPackaging(['BEB-TB-1L' => 14, 'BEB-TB-500' => 12, 'BEB-TB-GAL' => 1], $tuba);
            $this->registerProduction('Tuba', ['ING-SAVIA-L' => $savia], ['GRA-TUBA' => $tuba], $packagings);
        }

        // Dulces cada dos días.
        if ($d % 2 === 0 && $this->stock($this->matriz, 'GRA-PULPA') >= 15) {
            $this->at($day, 8, 0);
            $outputs = array_map(fn($q) => max(1, (int) round($q * $scale())), [
                'CCD-NZ-1P' => 40, 'CCD-LM-1P' => 40, 'CCD-GR-1P' => 30, 'CCD-MG-1P' => 30, 'CCD-MC-1P' => 30, 'CCD-BL-1P' => 30,
                'BAR-MX-1P' => 40, 'BAR-NZ-CH' => 25, 'BAR-CC-001' => 25, 'BAR-CF-001' => 20, 'BAR-RP-001' => 20, 'BAR-CN-001' => 20,
                'DUR-DZ-1P' => 30, 'DUR-LM-1P' => 30, 'PEL-1P' => 25, 'POS-DL-001' => 30, 'POS-CU-001' => 20, 'POS-CU-GDE' => 8,
            ]);
            $inputs = array_filter([
                'GRA-PULPA' => 15,
                'ING-AZU-KG' => min(7, $this->stock($this->matriz, 'ING-AZU-KG')),
                'ING-LCL-LATA' => min(6, $this->stock($this->matriz, 'ING-LCL-LATA')),
                'ING-NUEZ-KG' => min(2, $this->stock($this->matriz, 'ING-NUEZ-KG')),
                'DER-RP-001' => min(2, max(0, $this->stock($this->matriz, 'DER-RP-001') - 10)),
            ]);
            $this->registerProduction('Dulces', $inputs, $outputs);
        }

        // Rallado y tostado cada tres días: el granel se envasa al día siguiente.
        if ($d % 3 === 1 && $this->stock($this->matriz, 'GRA-PULPA') >= 30) {
            $this->at($day, 9, 0);
            $this->registerProduction('Rallado y tostado', ['GRA-PULPA' => 30], [
                'GRA-RAL' => round(8 * $scale(), 1), 'GRA-TOS' => round(4 * $scale(), 1),
                'GRA-RAL-NAT' => 2, 'GRA-TOS-NAT' => 1, 'GRA-HOJ' => 1,
            ]);
        }
        if ($d % 3 === 2) {
            $this->at($day, 9, 0);
            $this->packLater([
                'RAL-CR-1K' => 3, 'RAL-CR-500' => 4, 'RAL-CT-1K' => 2, 'RAL-CT-500' => 2,
                'RAL-CRN-1K' => 1, 'RAL-CRN-500' => 1, 'RAL-CTN-500' => 1, 'RAL-HJ-1K' => 0, 'RAL-HJ-500' => 2,
                'DER-PULPA-BOL' => 20,
            ]);
        }

        // En la tarde se envasa lo que quedó de la mañana (deja un poco).
        foreach ([
            'GRA-AGUA' => ['BEB-AC-GAL' => 3, 'BEB-AC-1L' => 15],
            'GRA-TUBA' => ['BEB-TB-GAL' => 2, 'BEB-TB-1L' => 6],
            'GRA-HORCH' => ['BEB-HC-GAL' => 1, 'BEB-HC-1L' => 6],
        ] as $bulk => $targets) {
            $this->at($day, 15, 0);
            $leftover = $this->stock($this->matriz, $bulk) - 5;
            if ($leftover >= 8) {
                $this->packLater($targets, $leftover);
            }
        }
    }

    /** Registra una producción completa (con su envasado) como lo hace la app. */
    private function registerProduction(string $process, array $inputs, array $outputs, array $packagings = [], array $wastes = []): void
    {
        $manager = $this->managers[$this->matriz->id];
        $this->api($manager, $this->matriz, 'POST', 'production-orders', [
            'kind' => 'process',
            'formula_id' => $this->formulas[$process] ?? null,
            'production_date' => now()->toDateString(),
            'responsible_user_id' => $manager->id,
            'consumptions' => $this->lines($inputs),
            'outputs' => $this->lines($outputs),
            'packagings' => $this->packagingLines($packagings),
            'wastes' => collect($wastes)->map(fn($w, $code) => [
                'product_id' => $this->products[$code]->id,
                'unit_id' => $this->products[$code]->unit_id,
                'quantity' => $w[0],
                'reason' => $w[1],
                'notes' => $w[2] ?? null,
            ])->values()->all(),
            'affect_stock' => true,
        ]);
        $this->count('producciones');
        if ($packagings) {
            $this->count('producciones con envasado');
        }
    }

    /**
     * Envasado aparte, con el granel que hay en la sucursal. $limit acota el
     * granel a usar (el agua de la tarde deja un poco para el día siguiente).
     */
    private function packLater(array $targets, ?float $limit = null): void
    {
        $byBulk = [];
        foreach (array_filter($targets) as $code => $quantity) {
            $byBulk[$this->recipes[$code]['bulk']][$code] = $quantity;
        }

        $packagings = [];
        $materials = [];
        foreach ($byBulk as $bulk => $group) {
            $available = $this->stock($this->matriz, $bulk);
            $packagings += $this->fitPackaging($group, $limit !== null ? min($limit, $available) : $available, $materials);
        }
        if (!$packagings) {
            return;
        }

        $manager = $this->managers[$this->matriz->id];
        $this->api($manager, $this->matriz, 'POST', 'production-orders', [
            'kind' => 'packaging',
            'production_date' => now()->toDateString(),
            'responsible_user_id' => $manager->id,
            'packagings' => $this->packagingLines($packagings),
            'affect_stock' => true,
        ]);
        $this->count('envasados aparte');
    }

    /**
     * Cuánto de cada presentación alcanza con el granel y el material que hay:
     * se envasa en orden y lo que no alcanza se recorta. $materials es lo que
     * queda de cada material; se comparte entre llamadas del mismo registro.
     *
     * @return array<string, int>
     */
    private function fitPackaging(array $targets, float $bulk, array &$materials = []): array
    {
        $fit = [];
        foreach ($targets as $code => $wanted) {
            $recipe = $this->recipes[$code];
            $max = $recipe['content'] > 0 ? (int) floor(($bulk + 0.0001) / $recipe['content']) : 0;
            foreach ($recipe['materials'] as $material => $perUnit) {
                $materials[$material] ??= $this->stock($this->matriz, $material);
                $max = min($max, (int) floor($materials[$material] / $perUnit));
            }
            $quantity = max(0, min((int) $wanted, $max));
            if ($quantity === 0) {
                continue;
            }
            $fit[$code] = $quantity;
            $bulk -= $quantity * $recipe['content'];
            foreach ($recipe['materials'] as $material => $perUnit) {
                $materials[$material] -= $quantity * $perUnit;
            }
        }

        return $fit;
    }

    private function packagingLines(array $packagings): array
    {
        return collect($packagings)->map(fn($q, $code) => [
            'product_id' => $this->products[$code]->id,
            'quantity' => $q,
        ])->values()->all();
    }

    // ─── Transferencias matriz → sucursal ────────────────────────────────────

    private function restock(Location $branch, Carbon $day): void
    {
        $targets = [];
        foreach ($this->products as $code => $p) {
            $target = match (true) {
                str_starts_with($code, 'BEB-GAL'), str_ends_with($code, 'GAL') => 4,
                str_starts_with($code, 'BEB-') => 14,
                str_starts_with($code, 'CCD-'), str_starts_with($code, 'BAR-'), str_starts_with($code, 'DUR-'),
                str_starts_with($code, 'PEL-'), str_starts_with($code, 'POS-') => 10,
                str_starts_with($code, 'RAL-') && !str_contains($code, '10K') && isset($p->sale_price) && $p->sale_price > 0 => 3,
                $code === 'DER-PULPA-BOL' => 5,
                str_starts_with($code, 'DER-') => 3,
                $code === 'COC-ENT-001' => 20,
                default => 0,
            };
            if ($target === 0 || !$p->for_sale) {
                continue;
            }
            $missing = $target - $this->stock($branch, $code);
            $available = $this->stock($this->matriz, $code) - ($code === 'COC-ENT-001' ? 150 : 5);
            $qty = (int) min($missing, $available);
            if ($missing >= $target * 0.4 && $qty > 0) {
                $targets[$code] = $qty;
            }
        }
        if (!$targets) {
            return;
        }

        $this->at($day, 8, 0);
        $requester = $this->managers[$branch->id];
        $matrizManager = $this->managers[$this->matriz->id];
        $transfer = $this->api($requester, $branch, 'POST', 'inventory-transfers', [
            'from_location_id' => $this->matriz->id,
            'to_location_id' => $branch->id,
            'notes' => 'Surtido de sucursal',
            'details' => collect($targets)->map(fn($q, $code) => [
                'product_id' => $this->products[$code]->id,
                'unit_id' => $this->products[$code]->unit_id,
                'quantity_requested' => $q,
                'unit_cost' => (float) ($this->products[$code]->purchase_price ?? 0),
            ])->values()->all(),
        ])['data'];
        $id = $transfer['id'];

        if (mt_rand(1, 30) === 1) {
            $this->api($matrizManager, $this->matriz, 'POST', "inventory-transfers/{$id}/reject", ['rejection_reason' => 'Sin unidad de reparto hoy']);
            $this->count('transferencias rechazadas');
            return;
        }

        $this->api($matrizManager, $this->matriz, 'POST', "inventory-transfers/{$id}/approve", []);
        $details = DB::table('inventory_transfer_details')->where('transfer_id', $id)->get(['id', 'product_id', 'quantity_requested']);

        $this->at($day, 8, 40);
        $this->api($matrizManager, $this->matriz, 'POST', "inventory-transfers/{$id}/ship", [
            'items' => $details->map(fn($det) => ['detail_id' => $det->id, 'quantity_shipped' => (float) $det->quantity_requested])->all(),
        ], ['evidence' => [new UploadedFile($this->evidencePath, 'carga.jpg', 'image/jpeg', null, true)]]);

        $this->at($day, 9, 20);
        // Rara vez llega algo dañado: se reporta con motivo, comentario y foto.
        $items = [];
        $files = [];
        foreach ($details->values() as $index => $det) {
            $shipped = (float) $det->quantity_requested;
            $damaged = $shipped >= 5 && mt_rand(1, 40) === 1;
            $items[$index] = ['detail_id' => $det->id, 'quantity_received' => $damaged ? $shipped - 1 : $shipped];
            if ($damaged) {
                $items[$index] += ['adjustment_reason' => 'damage', 'adjustment_comment' => 'Llegó golpeado en el camión'];
                $files['items'][$index]['adjustment_evidence'] = [new UploadedFile($this->evidencePath, 'golpe.jpg', 'image/jpeg', null, true)];
            }
        }
        $this->api($requester, $branch, 'POST', "inventory-transfers/{$id}/receive", ['items' => $items], $files);
        $this->count('transferencias');
    }

    // ─── Ventas: turno de caja por cajero ────────────────────────────────────

    private function salesDay(Location $location, Carbon $day): void
    {
        $weekend = $day->isWeekend();
        $isMatriz = $location->id === $this->matriz->id;
        $sessions = [];

        foreach ($this->cashiers[$location->id] as $i => $cashier) {
            $this->at($day, 9, $i * 5);
            $sessions[] = [$cashier, $this->api($cashier, $location, 'POST', 'cash-register-sessions/open', [
                'cash_register_id' => $this->registers[$location->id][$i],
                'opening_amount' => 500,
            ])['data']['id']];
        }

        $count = mt_rand($isMatriz ? 18 : 8, $isMatriz ? 30 : 18) + ($weekend ? mt_rand(4, 10) : 0);
        $minutes = collect(range(1, $count))->map(fn() => mt_rand(30, 11 * 60))->sort()->values();

        foreach ($minutes as $minute) {
            [$cashier] = $sessions[array_rand($sessions)];
            $basket = $this->basket($location);
            if (!$basket) {
                break;
            }
            $this->at($day, 9, $minute);

            $total = array_sum(array_map(fn($l) => $l['price'] * $l['quantity'], $basket));
            $roll = mt_rand(1, 100);
            if ($roll <= 62) {
                $tendered = $total <= 50 ? (mt_rand(0, 1) ? 50 : $total) : ceil($total / 100) * 100 + (mt_rand(0, 2) ? 0 : 100);
                $payments = [['method' => 'cash', 'amount' => $total, 'tendered' => max($tendered, $total)]];
            } elseif ($roll <= 90 || $total < 60) {
                $payments = [['method' => 'card', 'amount' => $total]];
            } else {
                $cash = floor($total / 2 / 10) * 10;
                $payments = [['method' => 'cash', 'amount' => $cash, 'tendered' => $cash], ['method' => 'card', 'amount' => round($total - $cash, 2)]];
            }

            $sale = $this->api($cashier, $location, 'POST', 'pos/sales', [
                'details' => array_map(fn($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $basket),
                'payments' => $payments,
                'customer_id' => mt_rand(1, 12) === 1 ? $this->customerIds[array_rand($this->customerIds)] : null,
            ])['data'];
            $this->count('ventas');

            // Alguna se cancela (la cancela el encargado).
            if (mt_rand(1, 60) === 1) {
                $this->at($day, 9, $minute + 3);
                $this->api($this->managers[$location->id], $location, 'POST', "sales/{$sale['id']}/cancel");
                $this->count('ventas canceladas');
            }
        }

        foreach ($sessions as $i => [$cashier, $sessionId]) {
            $this->at($day, 20, 30 + $i * 5);
            $expected = (float) $this->api($cashier, $location, 'GET', "cash-register-sessions/{$sessionId}")['summary']['expected_cash'];
            // Casi siempre cuadra; a veces falta o sobra algo.
            $roll = mt_rand(1, 100);
            $counted = match (true) {
                $roll <= 82 => $expected,
                $roll <= 94 => $expected - mt_rand(1, 10) * 5,
                default => $expected + mt_rand(1, 4) * 5,
            };
            $this->api($cashier, $location, 'POST', "cash-register-sessions/{$sessionId}/close", [
                'counted_cash' => max(0, $counted),
                'notes' => $counted < $expected ? 'Faltante al contar' : null,
            ]);
            $this->count('turnos');
        }
    }

    /** 1–4 renglones de productos con existencia en la sucursal. */
    private function basket(Location $location): array
    {
        $available = DB::table('product_location as pl')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->where('pl.location_id', $location->id)
            ->where('pl.active', true)
            ->where('p.for_sale', true)
            ->where('p.sale_price', '>', 0)
            ->where('pl.current_stock', '>=', 1)
            ->where('p.code', 'not like', 'PET-%')
            ->get(['p.id', 'p.code', 'p.sale_price', 'pl.current_stock']);
        if ($available->isEmpty()) {
            return [];
        }

        // Las bebidas y los dulces son lo que más se vende.
        $weighted = $available->flatMap(fn($p) => array_fill(0, match (true) {
            str_starts_with($p->code, 'BEB-') => 6,
            str_starts_with($p->code, 'CCD-'), str_starts_with($p->code, 'BAR-') => 4,
            default => 1,
        }, $p));

        $lines = [];
        foreach (range(1, mt_rand(1, 100) <= 60 ? 1 : mt_rand(2, 4)) as $_) {
            $p = $weighted->random();
            $qty = min((int) $p->current_stock, mt_rand(1, 100) <= 75 ? 1 : mt_rand(2, 4));
            if (isset($lines[$p->id]) || $qty < 1) {
                continue;
            }
            $lines[$p->id] = ['product_id' => $p->id, 'quantity' => $qty, 'price' => (float) $p->sale_price];
        }

        return array_values($lines);
    }

    // ─── Gastos y ajustes ────────────────────────────────────────────────────

    private function expenses(Carbon $day, int $d): void
    {
        foreach ([$this->matriz, ...$this->branches] as $i => $location) {
            $manager = $this->managers[$location->id];
            $list = [];
            if (($d + $i) % 4 === 0) {
                $list[] = ['suministros', mt_rand(8, 30) * 10, 'efectivo', true, 'Bolsas, servilletas y vasos'];
            }
            if (($d + $i) % 6 === 0) {
                $list[] = ['otros', mt_rand(4, 12) * 10, 'efectivo', true, 'Hielo'];
            }
            if ($day->day === 1 || ($d === 1 && $day->day > 1 && $i < 3)) {
                $list[] = ['alquiler', $location->id === $this->matriz->id ? 18000 : mt_rand(8, 12) * 1000, 'transferencia', false, 'Renta del local'];
            }
            if ($day->day === 5) {
                $list[] = ['servicios', mt_rand(9, 25) * 100, 'transferencia', false, 'Recibo de luz CFE'];
            }
            if ($day->day === 15 || $day->day === 30) {
                $list[] = ['nomina', $location->id === $this->matriz->id ? 24000 : 9000, 'transferencia', false, 'Nómina quincenal'];
            }
            if ($location->id === $this->matriz->id && $d % 3 === 1) {
                $list[] = ['transporte', mt_rand(40, 90) * 10, 'efectivo', true, 'Gasolina camioneta de reparto'];
            }
            if ($location->id === $this->matriz->id && $d % 10 === 5) {
                $list[] = ['mantenimiento', mt_rand(5, 30) * 100, 'tarjeta', false, 'Mantenimiento de máquina peladora'];
            }

            foreach ($list as $j => [$category, $amount, $method, $fromCash, $description]) {
                $this->at($day, 12, $i * 3 + $j);
                $this->api($manager, $location, 'POST', 'expenses', [
                    'category' => $category,
                    'amount' => $amount,
                    'payment_method' => $method,
                    'description' => $description,
                    'expense_date' => $day->toDateString(),
                    'paid_from_cash' => $fromCash,
                ]);
                $this->count('gastos');
            }
        }
    }

    private function adjustments(Carbon $day): void
    {
        foreach ([$this->matriz, ...$this->branches] as $i => $location) {
            $candidates = DB::table('product_location as pl')->join('products as p', 'p.id', '=', 'pl.product_id')
                ->where('pl.location_id', $location->id)->where('pl.current_stock', '>=', 3)
                ->where(fn($q) => $q->where('p.code', 'like', 'BEB-%')->orWhere('p.code', 'like', 'CCD-%'))
                ->inRandomOrder()->limit(mt_rand(1, 2))->get(['p.id', 'p.unit_id']);
            if ($candidates->isEmpty()) {
                continue;
            }
            $this->at($day, 21, $i * 2);
            $reasons = ['damage', 'expiry', 'loss'];
            $this->api($this->managers[$location->id], $location, 'POST', 'inventory-adjustments', [
                'details' => $candidates->map(fn($p) => [
                    'product_id' => $p->id,
                    'direction' => 'out',
                    'quantity' => mt_rand(1, 2),
                    'unit_id' => $p->unit_id,
                    'reason_code' => $reasons[array_rand($reasons)],
                    'notes' => 'Merma semanal',
                ])->all(),
            ]);
            $this->count('ajustes');
        }
    }

    // ─── Utilidades ──────────────────────────────────────────────────────────

    /**
     * Llama a la API como lo hace la app (token del usuario + headers de
     * compañía y sucursal). Cualquier error detiene el seeder.
     */
    private function api(User $user, Location $location, string $method, string $uri, array $data = [], array $files = []): array
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens[$user->id],
            'HTTP_X_COMPANY_ID' => (string) $this->company->id,
            'HTTP_X_LOCATION_ID' => (string) $location->id,
        ];
        $url = '/api/auth/admin/' . $uri;

        if ($method === 'GET' || $files) {
            $request = Request::create($url, $method, $data, [], $files, $server);
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $request = Request::create($url, $method, [], [], [], $server, json_encode($data));
        }

        // Cada petición resuelve de nuevo usuario, compañía y sucursal.
        Auth::forgetGuards();
        CurrentCompany::clear();
        CurrentLocation::clear();

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(sprintf(
                "%s %s como %s en %s (%s) → %d\n%s",
                $method, $uri, $user->email, $location->name, now()->toDateTimeString(),
                $response->getStatusCode(), mb_substr($response->getContent(), 0, 800)
            ));
        }

        return json_decode($response->getContent(), true) ?? [];
    }

    private function at(Carbon $day, int $hour, int $minute = 0): void
    {
        Carbon::setTestNow($day->copy()->setTime($hour, 0)->addMinutes($minute));
    }

    private function stock(Location $location, string $code): float
    {
        return (float) DB::table('product_location')
            ->where('location_id', $location->id)
            ->where('product_id', $this->products[$code]->id)
            ->value('current_stock');
    }

    private function lines(array $quantities): array
    {
        return collect($quantities)->map(fn($q, $code) => [
            'product_id' => $this->products[$code]->id,
            'unit_id' => $this->products[$code]->unit_id,
            'quantity' => $q,
        ])->values()->all();
    }

    private function count(string $what): void
    {
        $this->stats[$what] = ($this->stats[$what] ?? 0) + 1;
    }

    private function shortName(Location $location): string
    {
        return trim(str_replace(['Cocos Francisco', 'Sucursal'], '', $location->name));
    }

    private function slug(Location $location): string
    {
        return str($this->shortName($location))->ascii()->lower()->replace(' ', '')->toString();
    }

    private function cleanup(): void
    {
        foreach ([$this->admin, ...array_values($this->managers), ...array_merge(...array_values($this->cashiers))] as $user) {
            $user->tokens()->where('name', 'demo-seeder')->delete();
        }
        @unlink($this->evidencePath);
    }
}
