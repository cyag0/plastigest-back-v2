<?php

$company = App\Models\Company::where('name', 'Cocos Francisco')->first();

if (!$company) {
    echo "Error: No se encontró la compañía Cocos Francisco\n";
    exit(1);
}

$supplier = App\Models\Supplier::create([
    'name' => 'Proveedor Coco S.A.',
    'contact_name' => 'Juan Pérez',
    'email' => 'contacto@proveedorcoco.com',
    'phone' => '3221234567',
    'address' => 'Av. Principal #123, Puerto Vallarta, Jalisco',
    'company_id' => $company->id,
    'is_active' => true,
]);

echo "✅ Proveedor creado exitosamente:\n";
echo "   Nombre: {$supplier->name}\n";
echo "   ID: {$supplier->id}\n";
echo "   Contacto: {$supplier->contact_name}\n";
echo "   Email: {$supplier->email}\n";
echo "   Teléfono: {$supplier->phone}\n";
