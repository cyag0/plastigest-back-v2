@extends('pdf.layout')

@section('title', 'Orden de Compra - ' . ($purchase->purchase_number ?? $purchase->id))

@section('document-title', 'Orden de Compra #' . ($purchase->purchase_number ?? $purchase->id))

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
            text-align: right;
        }

        .totals-label {
            color: #725C3A;
            font-weight: bold;
            font-size: 12px;
        }

        .totals-value {
            color: #333;
            font-size: 18px;
            font-weight: bold;
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
                    <span class="info-grid-value">{{ $purchase->purchase_number ?? '#' . $purchase->id }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Estado:</span>
                    <span class="info-grid-value">
                        @php
                            $statusLabels = [
                                'draft'      => 'Borrador',
                                'ordered'    => 'Pedido',
                                'in_transit' => 'En tránsito',
                                'received'   => 'Recibido',
                                'cancelled'  => 'Cancelado',
                            ];
                            $statusClass = [
                                'draft'      => 'badge-info',
                                'ordered'    => 'badge-warning',
                                'in_transit' => 'badge-warning',
                                'received'   => 'badge-success',
                                'cancelled'  => 'badge-danger',
                            ];
                        @endphp
                        <span class="badge {{ $statusClass[$purchase->status] ?? 'badge-info' }}">
                            {{ $statusLabels[$purchase->status] ?? $purchase->status }}
                        </span>
                    </span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Proveedor:</span>
                    <span class="info-grid-value">{{ $purchase->supplier->name ?? 'Sin proveedor' }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Sucursal:</span>
                    <span class="info-grid-value">{{ $purchase->location->name ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Fecha compra:</span>
                    <span class="info-grid-value">
                        {{ $purchase->purchase_date ? \Carbon\Carbon::parse($purchase->purchase_date)->format('d/m/Y') : 'N/A' }}
                    </span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Documento:</span>
                    <span class="info-grid-value">{{ $purchase->document_number ?? 'N/A' }}</span>
                </div>
            </div>
            @if ($purchase->expected_delivery_date)
                <div class="info-grid-row">
                    <div class="info-grid-cell">
                        <span class="info-grid-label">Entrega esperada:</span>
                        <span class="info-grid-value">
                            {{ \Carbon\Carbon::parse($purchase->expected_delivery_date)->format('d/m/Y') }}
                        </span>
                    </div>
                    @if ($purchase->delivery_date)
                        <div class="info-grid-cell">
                            <span class="info-grid-label">Entregado:</span>
                            <span class="info-grid-value">
                                {{ \Carbon\Carbon::parse($purchase->delivery_date)->format('d/m/Y') }}
                            </span>
                        </div>
                    @endif
                </div>
            @endif
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Método de pago:</span>
                    <span class="info-grid-value">
                        @php
                            $paymentLabels = [
                                'cash'     => 'Efectivo',
                                'card'     => 'Tarjeta',
                                'transfer' => 'Transferencia',
                                'other'    => 'Otro',
                            ];
                        @endphp
                        {{ $paymentLabels[$purchase->payment_method] ?? $purchase->payment_method ?? 'N/A' }}
                    </span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Creado por:</span>
                    <span class="info-grid-value">{{ $purchase->user->name ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabla de Productos --}}
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Producto</th>
                <th style="width: 12%; text-align: center;">Cantidad</th>
                <th style="width: 12%; text-align: right;">Precio Unit.</th>
                <th style="width: 12%; text-align: center;">Recibido</th>
                <th style="width: 14%; text-align: right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($purchase->details as $index => $detail)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        <strong>{{ $detail->product->name ?? 'N/A' }}</strong>
                        @if ($detail->product && $detail->product->code)
                            <br><small style="color: #725C3A;">{{ $detail->product->code }}</small>
                        @endif
                        @if ($detail->package)
                            <br><small style="color: #725C3A;">Paquete: {{ $detail->package->package_name ?? '' }}</small>
                        @endif
                    </td>
                    <td class="text-center">
                        {{ number_format((float) $detail->quantity, 2) }}
                        <small style="color: #725C3A;">{{ $detail->unit->abbreviation ?? '' }}</small>
                    </td>
                    <td class="text-right">${{ number_format((float) $detail->unit_price, 2) }}</td>
                    <td class="text-center">
                        @if ($detail->quantity_received !== null)
                            @php
                                $diff = (float) $detail->quantity_received - (float) $detail->quantity;
                            @endphp
                            {{ number_format((float) $detail->quantity_received, 2) }}
                            @if ($diff < 0)
                                <br><small style="color: #B85450;">Faltante: {{ number_format(abs($diff), 2) }}</small>
                            @elseif ($diff > 0)
                                <br><small style="color: #5A8A5A;">Sobrante: {{ number_format($diff, 2) }}</small>
                            @endif
                        @else
                            <span style="color: #B3B792;">-</span>
                        @endif
                    </td>
                    <td class="text-right">${{ number_format((float) ($detail->total ?? $detail->quantity * $detail->unit_price), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay productos en esta compra
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Total --}}
    <div class="totals-card">
        <div class="totals-label">TOTAL DE LA COMPRA</div>
        <div class="totals-value">${{ number_format((float) $purchase->total, 2) }}</div>
    </div>

    {{-- Notas --}}
    @if ($purchase->notes)
        <div style="margin-top: 20px;">
            <div style="font-weight: bold; color: #725C3A; margin-bottom: 4px;">Notas:</div>
            <div style="color: #333; padding: 8px; background-color: #FAFAFA; border-radius: 4px;">
                {{ $purchase->notes }}
            </div>
        </div>
    @endif

    {{-- Historial de cambios de estado --}}
    @if (!empty($purchase->metadata))
        <div style="margin-top: 25px;">
            <div style="font-weight: bold; color: #725C3A; margin-bottom: 8px;">Historial de cambios:</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 25%;">Fecha</th>
                        <th style="width: 25%;">De</th>
                        <th style="width: 25%;">A</th>
                        <th style="width: 25%;">Por</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchase->metadata as $entry)
                        <tr>
                            <td>
                                <small>{{ \Carbon\Carbon::parse($entry['at'])->format('d/m/Y H:i') }}</small>
                            </td>
                            <td>{{ $entry['from'] ?? '-' }}</td>
                            <td>{{ $entry['to'] ?? '-' }}</td>
                            <td>{{ $entry['by_user_name'] ?? 'N/A' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Resolución de discrepancia si existe --}}
    @if (!empty($discrepancyResolution))
        <div style="margin-top: 20px;">
            <div style="font-weight: bold; color: #725C3A; margin-bottom: 4px;">Resolución de discrepancia:</div>
            <div style="color: #333; padding: 8px; background-color: #FAFAFA; border-radius: 4px;">
                <strong>Tipo:</strong>
                @php
                    $resolutionLabels = [
                        'credit_note' => 'Nota de crédito',
                        'adjustment'  => 'Ajuste de inventario',
                        'no_action'   => 'Sin acción',
                        'other'       => 'Otro',
                    ];
                @endphp
                {{ $resolutionLabels[$discrepancyResolution['resolution']['type']] ?? $discrepancyResolution['resolution']['type'] }}
                @if (!empty($discrepancyResolution['resolution']['notes']))
                    <br><strong>Notas:</strong> {{ $discrepancyResolution['resolution']['notes'] }}
                @endif
            </div>
        </div>
    @endif
@endsection
