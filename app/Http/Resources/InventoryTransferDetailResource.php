<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Models\Unit;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class InventoryTransferDetailResource extends JsonResource
{
    /**
     * Caché de la petición (en los atributos del Request, así no sobrevive a
     * otra petición): stock disponible en el origen e imagen principal de los
     * productos de cada transferencia, y el catálogo de unidades.
     *
     * Antes cada renglón cargaba su transferencia, la sucursal de origen, el
     * stock, la imagen y la unidad, y además escribía un Log::info: el listado
     * de 137 transferencias (≈2,000 renglones) tardaba 9 s. Ahora es una
     * consulta de stock por transferencia y una de unidades por petición.
     */
    private const CACHE_KEY = 'inventory_transfer_detail_resource';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $productId = (int) $this->product_id;
        $transferData = $this->transferData($request);

        // Disponible en el origen = existencia - reservado.
        $availableStock = $transferData['stock'][$productId] ?? 0.0;

        $unit = $this->unitData($request);

        // Cantidades leídas en crudo (columnas decimal(…,3)/(…,2)): el cast
        // decimal de Eloquent es caro y aquí se repetía por cada renglón.
        // Mismo resultado que los accessors difference/has_difference del modelo.
        $raw = $this->resource->getAttributes();
        $shipped = (float) ($raw['quantity_shipped'] ?? 0);
        $received = (float) ($raw['quantity_received'] ?? 0);
        $difference = $shipped - $received;

        return [
            'id' => $this->id,
            'transfer_id' => $this->transfer_id,
            'product_id' => $this->product_id,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
                'sku' => $this->product?->sku,
                'code' => $this->product?->code,
                'main_image' => $this->mainImage($request),
            ],
            'package_id' => $this->package_id,
            'package' => $this->when($this->package_id && $this->package, function () {
                return [
                    'id' => $this->package->id,
                    'package_name' => $this->package->package_name,
                    'quantity_per_package' => (float) $this->package->quantity_per_package,
                    'barcode' => $this->package->barcode,
                ];
            }),
            'unit_id' => $this->unit_id,
            'unit' => $this->when((bool) $this->unit_id && $unit !== null, fn() => $unit),
            // Lo pedido se queda como se pidió; lo aprobado va aparte (null
            // mientras no se aprueba).
            'quantity_requested' => (float) ($raw['quantity_requested'] ?? 0),
            'quantity_approved' => isset($raw['quantity_approved']) ? (float) $raw['quantity_approved'] : null,
            'quantity_shipped' => $shipped,
            'quantity_received' => $received,
            'available_stock' => $availableStock,
            'difference' => $difference,
            'has_difference' => $difference > 0,
            'unit_cost' => (float) ($raw['unit_cost'] ?? 0),
            'total_cost' => (float) ($raw['total_cost'] ?? 0),
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'damage_report' => $this->damage_report,

            'created_at' => $this->isoTimestamp($request, 'created_at'),
            'updated_at' => $this->isoTimestamp($request, 'updated_at'),
        ];
    }

    /**
     * Fecha en ISO 8601 (UTC), igual que `$this->created_at?->toISOString()`.
     * Los renglones de una transferencia suelen compartir el mismo timestamp,
     * así que se memoriza por valor para no parsear la fecha en cada renglón.
     */
    private function isoTimestamp(Request $request, string $attribute): ?string
    {
        $value = $this->resource->getAttributes()[$attribute] ?? null;
        if ($value === null) {
            return null;
        }

        $cache = $request->attributes->get(self::CACHE_KEY . '.iso', []);
        $key = is_scalar($value) ? (string) $value : null;

        if ($key !== null && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $iso = $this->resource->{$attribute}?->toISOString();

        if ($key !== null) {
            $cache[$key] = $iso;
            $request->attributes->set(self::CACHE_KEY . '.iso', $cache);
        }

        return $iso;
    }

    /**
     * Stock disponible en el origen de todos los productos de la transferencia
     * de este renglón, con una sola consulta por transferencia.
     *
     * @return array{stock: array<int, float>, product_ids: int[]}
     */
    private function transferData(Request $request): array
    {
        $cache = $request->attributes->get(self::CACHE_KEY, []);
        $transferId = (int) $this->transfer_id;

        if (!isset($cache['transfers'][$transferId])) {
            $stock = [];
            $productIds = [];

            $rows = DB::table('inventory_transfer_details as d')
                ->join('inventory_transfers as t', 't.id', '=', 'd.transfer_id')
                ->leftJoin('product_location as pl', function ($join) {
                    $join->on('pl.product_id', '=', 'd.product_id')
                        ->on('pl.location_id', '=', 't.from_location_id');
                })
                ->where('d.transfer_id', $transferId)
                ->get(['d.product_id', 'pl.current_stock', 'pl.reserved_stock']);

            foreach ($rows as $row) {
                $productIds[] = (int) $row->product_id;
                if ($row->current_stock !== null) {
                    $stock[(int) $row->product_id] = (float) $row->current_stock - (float) $row->reserved_stock;
                }
            }

            $cache['transfers'][$transferId] = [
                'stock' => $stock,
                'product_ids' => array_values(array_unique($productIds)),
            ];
            $request->attributes->set(self::CACHE_KEY, $cache);
        }

        return $cache['transfers'][$transferId];
    }

    /**
     * Imagen principal del producto. Si la relación ya viene cargada (detalle
     * de la transferencia) se usa; si no (listado), se cargan de una vez las de
     * los productos de la transferencia que todavía no se conocen.
     */
    private function mainImage(Request $request): ?array
    {
        $product = $this->product;
        if (!$product) {
            return null;
        }

        if ($product->relationLoaded('mainImage')) {
            $imagePath = $product->mainImage?->image_path;
        } else {
            $imagePath = $this->knownImages($request)[(int) $product->id] ?? null;
        }

        return $imagePath ? AppUploadUtil::formatFile(Files::PRODUCT_IMAGES_PATH, $imagePath) : null;
    }

    /**
     * Imágenes principales conocidas en la petición (product_id => ruta o
     * null). Solo consulta los productos de esta transferencia que faltan: las
     * transferencias comparten catálogo, así que después de unas cuantas ya no
     * hace falta consultar.
     *
     * @return array<int, ?string>
     */
    private function knownImages(Request $request): array
    {
        $productIds = $this->transferData($request)['product_ids'];

        $cache = $request->attributes->get(self::CACHE_KEY, []);
        $images = $cache['images'] ?? [];

        $missing = array_values(array_diff($productIds, array_keys($images)));

        if ($missing !== []) {
            foreach ($missing as $productId) {
                $images[$productId] = null;
            }

            $rows = DB::table('product_images')
                ->whereIn('product_id', $missing)
                ->where('image_type', 'main')
                ->orderBy('id')
                ->get(['product_id', 'image_path']);

            foreach ($rows as $row) {
                $images[(int) $row->product_id] ??= $row->image_path;
            }

            $cache['images'] = $images;
            $request->attributes->set(self::CACHE_KEY, $cache);
        }

        return $images;
    }

    /**
     * La unidad del renglón: la relación si viene cargada o, si no, el catálogo
     * de unidades (unas cuantas filas) cargado una vez por petición.
     *
     * @return array{id:int, name:string, abbreviation:string}|null
     */
    private function unitData(Request $request): ?array
    {
        if (!$this->unit_id) {
            return null;
        }

        if ($this->resource->relationLoaded('unit')) {
            $unit = $this->unit;
        } else {
            $cache = $request->attributes->get(self::CACHE_KEY, []);

            if (!isset($cache['units'])) {
                $cache['units'] = Unit::query()->get(['id', 'name', 'abbreviation'])->keyBy('id');
                $request->attributes->set(self::CACHE_KEY, $cache);
            }

            $unit = $cache['units']->get((int) $this->unit_id);
        }

        return $unit ? [
            'id' => $unit->id,
            'name' => $unit->name,
            'abbreviation' => $unit->abbreviation,
        ] : null;
    }
}
