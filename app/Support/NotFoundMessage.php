<?php

namespace App\Support;

use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Models\Admin\Role;
use App\Models\CashClosing;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\CustomerNote;
use App\Models\DeviceToken;
use App\Models\Expense;
use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferDetail;
use App\Models\Notification;
use App\Models\Operations\Formula;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\PurchaseV2;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesReport;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Texto en español de un 404 de la API. Laravel dice "No query results for
 * model [App\Models\Task] 7" de un findOrFail o de un {task} de la ruta que
 * no existe (o que es de otra empresa o sucursal), y "The route … could not
 * be found." de una ruta que no existe; la app los mostraba tal cual.
 */
final class NotFoundMessage
{
    public const GENERIC = 'Registro no encontrado';

    /** Mismo texto con el que la app traducía "The route … could not be found." */
    public const ROUTE = 'Esta función no está disponible en el servidor. Recarga la página o actualiza la app.';

    /** @var array<class-string, string> */
    private const BY_MODEL = [
        CashClosing::class => 'Cierre de caja no encontrado',
        CashRegister::class => 'Caja no encontrada',
        CashRegisterSession::class => 'Turno no encontrado',
        Category::class => 'Categoría no encontrada',
        Company::class => 'Empresa no encontrada',
        Customer::class => 'Cliente no encontrado',
        CustomerNote::class => 'Nota no encontrada',
        DeviceToken::class => 'Token no encontrado',
        Expense::class => 'Gasto no encontrado',
        Formula::class => 'Fórmula no encontrada',
        InventoryAdjustmentDetail::class => 'Ajuste no encontrado',
        InventoryCount::class => 'Conteo no encontrado',
        InventoryTransfer::class => 'Transferencia no encontrada',
        InventoryTransferDetail::class => 'Detalle de transferencia no encontrado',
        Location::class => 'Sucursal no encontrada',
        Notification::class => 'Notificación no encontrada',
        Product::class => 'Producto no encontrado',
        ProductPackage::class => 'Empaque no encontrado',
        ProductionOrder::class => 'Producción no encontrada',
        PurchaseV2::class => 'Compra no encontrada',
        Reminder::class => 'Recordatorio no encontrado',
        Role::class => 'Rol no encontrado',
        Sale::class => 'Venta no encontrada',
        SalesOrder::class => 'Pedido no encontrado',
        SalesReport::class => 'Reporte no encontrado',
        Supplier::class => 'Proveedor no encontrado',
        Task::class => 'Tarea no encontrada',
        TaskComment::class => 'Comentario no encontrado',
        Unit::class => 'Unidad no encontrada',
        User::class => 'Usuario no encontrado',
    ];

    /** "Tarea no encontrada", o el genérico si el modelo no tiene texto propio. */
    public static function forModel(?string $model): string
    {
        return self::BY_MODEL[ltrim((string) $model, '\\')] ?? self::GENERIC;
    }

    public static function of(ModelNotFoundException $e): string
    {
        return self::forModel($e->getModel());
    }

    /**
     * Mensaje del 404 que llega al manejador global. Laravel ya convirtió el
     * ModelNotFoundException en NotFoundHttpException (queda como previo).
     * Un abort(404, 'Compra no encontrada') conserva su mensaje.
     */
    public static function forHttpException(NotFoundHttpException $e, Request $request): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException) {
            return self::of($previous);
        }

        // Otras conversiones de Laravel (sole() sin resultados, un enum de la
        // ruta que no existe…): su texto es interno y en inglés.
        if ($previous !== null) {
            return self::GENERIC;
        }

        // Sin ruta resuelta: la URL no existe en la API.
        if ($request->route() === null) {
            return self::ROUTE;
        }

        $message = trim($e->getMessage());

        return $message !== '' ? $message : self::GENERIC;
    }
}
