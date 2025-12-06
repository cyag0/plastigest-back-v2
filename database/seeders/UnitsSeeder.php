<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar si ya existen unidades
        if (Unit::count() > 0) {
            $this->command->warn('⚠️  Ya existen unidades en la base de datos');
            return;
        }

        // Unidades básicas de cantidad
        $pieza = Unit::create([
            'name' => 'Pieza',
            'abbreviation' => 'pz',
        ]);

        $caja = Unit::create([
            'name' => 'Caja',
            'abbreviation' => 'cj',
        ]);

        $docena = Unit::create([
            'name' => 'Docena',
            'abbreviation' => 'dz',
        ]);

        $paquete = Unit::create([
            'name' => 'Paquete',
            'abbreviation' => 'pq',
        ]);

        // Unidades de peso
        $kilogramo = Unit::create([
            'name' => 'Kilogramo',
            'abbreviation' => 'kg',
        ]);

        $gramo = Unit::create([
            'name' => 'Gramo',
            'abbreviation' => 'g',
        ]);

        $tonelada = Unit::create([
            'name' => 'Tonelada',
            'abbreviation' => 'ton',
        ]);

        // Unidades de longitud
        $metro = Unit::create([
            'name' => 'Metro',
            'abbreviation' => 'm',
        ]);

        $centimetro = Unit::create([
            'name' => 'Centímetro',
            'abbreviation' => 'cm',
        ]);

        $milimetro = Unit::create([
            'name' => 'Milímetro',
            'abbreviation' => 'mm',
        ]);

        // Unidades de volumen
        $litro = Unit::create([
            'name' => 'Litro',
            'abbreviation' => 'L',
        ]);

        $mililitro = Unit::create([
            'name' => 'Mililitro',
            'abbreviation' => 'ml',
        ]);

        // Conversiones de cantidad
        $this->createConversion($caja, $pieza, 12); // 1 caja = 12 piezas
        $this->createConversion($docena, $pieza, 12); // 1 docena = 12 piezas
        $this->createConversion($paquete, $pieza, 100); // 1 paquete = 100 piezas

        // Conversiones de peso
        $this->createConversion($kilogramo, $gramo, 1000); // 1 kg = 1000 g
        $this->createConversion($tonelada, $kilogramo, 1000); // 1 ton = 1000 kg
        $this->createConversion($tonelada, $gramo, 1000000); // 1 ton = 1,000,000 g

        // Conversiones de longitud
        $this->createConversion($metro, $centimetro, 100); // 1 m = 100 cm
        $this->createConversion($metro, $milimetro, 1000); // 1 m = 1000 mm
        $this->createConversion($centimetro, $milimetro, 10); // 1 cm = 10 mm

        // Conversiones de volumen
        $this->createConversion($litro, $mililitro, 1000); // 1 L = 1000 ml

        $this->command->info('✅ Unidades y conversiones creadas exitosamente');
    }

    /**
     * Crear conversión bidireccional entre dos unidades
     */
    private function createConversion(Unit $from, Unit $to, float $factor): void
    {
        // Conversión directa
        UnitConversion::create([
            'from_unit_id' => $from->id,
            'to_unit_id' => $to->id,
            'factor' => $factor,
        ]);

        // Conversión inversa
        UnitConversion::create([
            'from_unit_id' => $to->id,
            'to_unit_id' => $from->id,
            'factor' => 1 / $factor,
        ]);
    }
}
