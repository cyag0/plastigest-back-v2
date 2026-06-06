@extends('pdf.layout')

@section('title', 'Venta - ' . ($sale->sale_number ?? $sale->id))

@section('document-title', 'Venta #' . ($sale->sale_number ?? $sale->id))

@section('styles')
    <style>
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .info-grid-row {
            display: table-row;
        }

        .info-grid-cell {
            display: table-cell;
            padding: 4px 8px;
            width: 50%;
        }

        .info-grid-label {
            font-weight: bold;
            color: #725C3A;
            display: inline-block;
            width: 110px;
        }

        .info-grid-value {
            color: #333;
        }

        .totals-card {
            background-color: #F4F1EA;
            padding: 12px;
            margin-top: 15px;
            border-radius: 5px;
            border-left: 4px solid #809671;
        }

        .totals-grid {
            display: table;
            width: 100%;
        }

        .totals-row {
            display: table-row;
        }

        .totals-cell {
            display: table-cell;
            padding: 3px 8px;
        }

        .totals-cell-label {
            text-align: right;
            color: #725C3A;
            font-size: 12px;
            width: 70%;
        }

        .totals-cell-value {
            text-align: right;
            color: #333;
            font-size: 13px;
            font-weight: 600;
            width: 30%;
        }

        .totals-grand {
            background-color: #809671;
            color: #fff;
            font-size: 18px;
            font-weight: bold;
            padding: 8px;
            border-radius: 4px;
        }
    </style>
@endsection

@section('content')
    {{-- Información General en 2 Columnas --}}
    <div class="info-section">
        <div class="info-grid">
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Número:</span>
                    <span class="info-grid-value">{{ $sale->sale_number ?? '#' . $sale->id }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Estado:</span>
                    <span class="info-grid-value">
                        @php
                            $statusLabels = [
                                'pending'   => 'Pendiente',
                                'closed'    => 'Cerrada',
                                'cancelled' => 'Cancelada',
                            ];
                            $statusClass = [
                                'pending'   => 'badge-warning',
                                'closed'    => 'badge-success',
                                'cancelled' => 'badge-danger',
                            ];
                            $statusValue = $sale->status instanceof \BackedEnum ? $sale->status->value : $sale->status;
                        @endphp
                        <span class="badge {{ $statusClass[$statusValue] ?? 'badge-info' }}">
                            {{ $statusLabels[$statusValue] ?? $statusValue }}
                        </span>
                    </span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Cliente:</span>
                    <span class="info-grid-value">
                        {{ $sale->customer->name ?? ($sale->content['customer_name'] ?? 'Público general') }}
                    </span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Sucursal:</span>
                    <span class="info-grid-value">{{ $sale->location->name ?? 'N/A' }}</span>
                </div>
            </div>
            @if (!empty($sale->content['customer_phone']) || !empty($sale->content['customer_email']))
                <div class="info-grid-row">
                    <div class="info-grid-cell">
                        <span class="info-grid-label">Teléfono:</span>
                        <span class="info-grid-value">{{ $sale->content['customer_phone'] ?? 'N/A' }}</span>
                    </div>
                    <div class="info-grid-cell">
                        <span class="info-grid-label">Email:</span>
                        <span class="info-grid-value">{{ $sale->content['customer_email'] ?? 'N/A' }}</span>
                    </div>
                </div>
            @endif
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Fecha:</span>
                    <span class="info-grid-value">
                        {{ $sale->sale_date ? \Carbon\Carbon::parse($sale->sale_date)->format('d/m/Y') : 'N/A' }}
                    </span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Vendedor:</span>
                    <span class="info-grid-value">{{ $sale->user->name ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Método de pago:</span>
                    <span class="info-grid-value">
                        @php
                            $paymentMethod = $sale->payment_method ?? ($sale->content['payment_method'] ?? null);
                            $paymentLabels = [
                                'cash'     => 'Efectivo',
                                'card'     => 'Tarjeta',
                                'transfer' => 'Transferencia',
                                'credit'   => 'Crédito',
                                'other'    => 'Otro',
                            ];
                        @endphp
                        {{ $paymentLabels[$paymentMethod] ?? $paymentMethod ?? 'N/A' }}
                    </span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Estatus de pago:</span>
                    <span class="info-grid-value">
                        @php
                            $paymentStatusLabels = [
                                'paid'    => 'Pagado',
                                'partial' => 'Parcial',
                                'pending' => 'Pendiente',
                            ];
                        @endphp
                        {{ $paymentStatusLabels[$sale->payment_status] ?? $sale->payment_status ?? 'N/A' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabla de Productos --}}
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 40%;">Producto</th>
                <th style="width: 12%; text-align: center;">Cantidad</th>
                <th style="width: 14%; text-align: right;">Precio Unit.</th>
                <th style="width: 13%; text-align: right;">Descuento</th>
                <th style="width: 16%; text-align: right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sale->details as $index => $detail)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        <strong>{{ $detail->product->name ?? 'N/A' }}</strong>
                        @if ($detail->product && $detail->product->code)
                            <br><small style="color: #725C3A;">{{ $detail->product->code }}</small>
                        @endif
                    </td>
                    <td class="text-center">
                        {{ number_format((float) $detail->quantity, 3) }}
                        <small style="color: #725C3A;">{{ $detail->unit->abbreviation ?? '' }}</small>
                    </td>
                    <td class="text-right">${{ number_format((float) $detail->unit_price, 2) }}</td>
                    <td class="text-right">
                        @if ((float) $detail->discount > 0)
                            -${{ number_format((float) $detail->discount, 2) }}
                        @else
                            <span style="color: #B3B792;">-</span>
                        @endif
                    </td>
                    <td class="text-right">${{ number_format((float) ($detail->total ?? $detail->subtotal ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay productos en esta venta
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totales --}}
    <div class="totals-card">
        <div class="totals-grid">
            @if ((float) $sale->subtotal > 0)
                <div class="totals-row">
                    <div class="totals-cell totals-cell-label">Subtotal:</div>
                    <div class="totals-cell totals-cell-value">${{ number_format((float) $sale->subtotal, 2) }}</div>
                </div>
            @endif
            @if ((float) $sale->discount > 0)
                <div class="totals-row">
                    <div class="totals-cell totals-cell-label">Descuento:</div>
                    <div class="totals-cell totals-cell-value">-${{ number_format((float) $sale->discount, 2) }}</div>
                </div>
            @endif
            @if ((float) $sale->tax > 0)
                <div class="totals-row">
                    <div class="totals-cell totals-cell-label">Impuesto:</div>
                    <div class="totals-cell totals-cell-value">${{ number_format((float) $sale->tax, 2) }}</div>
                </div>
            @endif
            <div class="totals-row">
                <div class="totals-cell totals-cell-label" style="font-size: 14px; padding-top: 8px;">
                    <strong>TOTAL:</strong>
                </div>
                <div class="totals-cell totals-cell-value" style="font-size: 16px; padding-top: 8px;">
                    <strong>${{ number_format((float) $sale->total, 2) }}</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- Notas --}}
    @if (!empty($sale->content['notes']) || !empty($sale->notes))
        <div style="margin-top: 20px;">
            <div style="font-weight: bold; color: #725C3A; margin-bottom: 4px;">Notas:</div>
            <div style="color: #333; padding: 8px; background-color: #FAFAFA; border-radius: 4px;">
                {{ $sale->content['notes'] ?? $sale->notes }}
            </div>
        </div>
    @endif
@endsection
