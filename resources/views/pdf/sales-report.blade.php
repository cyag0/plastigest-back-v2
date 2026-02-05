@extends('pdf.layout')

@section('title', 'Reporte de Ventas - ' . ($salesReport->location->name ?? 'N/A'))

@section('document-title', 'Reporte de Ventas - ' . ($salesReport->location->name ?? 'N/A'))

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
            width: 120px;
        }

        .info-grid-value {
            color: #333;
        }

        .summary-card {
            background-color: #F4F1EA;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            border-left: 4px solid #809671;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-row {
            display: table-row;
        }

        .summary-cell {
            display: table-cell;
            padding: 8px;
            width: 25%;
            text-align: center;
        }

        .summary-label {
            font-size: 8pt;
            color: #725C3A;
            text-transform: uppercase;
        }

        .summary-value {
            font-size: 14pt;
            font-weight: bold;
            color: #333;
            margin-top: 4px;
        }

        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #725C3A;
            margin-top: 20px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #809671;
        }

        .small-table {
            font-size: 8pt;
        }

        .small-table td,
        .small-table th {
            padding: 4px 6px;
        }
    </style>
@endsection

@section('content')
    {{-- Información General --}}
    <div class="info-section">
        <div class="info-grid">
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Fecha:</span>
                    <span
                        class="info-grid-value">{{ \Carbon\Carbon::parse($salesReport->report_date)->format('d/m/Y') }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Usuario:</span>
                    <span class="info-grid-value">{{ $salesReport->user->name ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Ubicación:</span>
                    <span class="info-grid-value">{{ $salesReport->location->name ?? 'N/A' }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Transacciones:</span>
                    <span class="info-grid-value">{{ $salesReport->transactions_count }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Resumen de Ventas --}}
    <div class="summary-card">
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell">
                    <div class="summary-label">Total Ventas</div>
                    <div class="summary-value">${{ number_format($salesReport->total_sales, 2) }}</div>
                </div>
                <div class="summary-cell">
                    <div class="summary-label">Efectivo</div>
                    <div class="summary-value">${{ number_format($salesReport->total_cash, 2) }}</div>
                </div>
                <div class="summary-cell">
                    <div class="summary-label">Tarjeta</div>
                    <div class="summary-value">${{ number_format($salesReport->total_card, 2) }}</div>
                </div>
                <div class="summary-cell">
                    <div class="summary-label">Transferencia</div>
                    <div class="summary-value">${{ number_format($salesReport->total_transfer, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Resumen de Gastos e Ingreso Neto --}}
    @if($totalExpenses > 0)
    <div class="summary-card" style="border-left-color: #DC2626;">
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell" style="width: 50%;">
                    <div class="summary-label" style="color: #DC2626;">Total Gastos</div>
                    <div class="summary-value" style="color: #DC2626;">-${{ number_format($totalExpenses, 2) }}</div>
                </div>
                <div class="summary-cell" style="width: 50%; background-color: #DCFCE7; border-radius: 5px;">
                    <div class="summary-label" style="color: #16A34A;">Ingreso Neto</div>
                    <div class="summary-value" style="color: #16A34A;">${{ number_format($salesReport->total_sales - $totalExpenses, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Notas --}}
    @if ($salesReport->notes)
        <div class="info-section">
            <strong style="color: #725C3A;">Notas:</strong>
            <div style="margin-top: 5px;">{{ $salesReport->notes }}</div>
        </div>
    @endif

    {{-- Detalle de Gastos --}}
    @if($expenses->count() > 0)
    <div class="section-title">Gastos del Día</div>
    <table class="small-table">
        <thead>
            <tr>
                <th style="width: 20%;">Categoría</th>
                <th style="width: 35%;">Descripción</th>
                <th style="width: 15%; text-align: center;">Método Pago</th>
                <th style="width: 15%;">Usuario</th>
                <th style="width: 15%; text-align: right;">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($expenses as $expense)
                <tr>
                    <td>{{ $expense->category_label }}</td>
                    <td>{{ $expense->description }}</td>
                    <td class="text-center">{{ $expense->payment_method_label }}</td>
                    <td>{{ $expense->user->name ?? 'N/A' }}</td>
                    <td class="text-right" style="color: #DC2626;">-${{ number_format($expense->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background-color: #DC2626; color: white; font-weight: bold;">
                <td colspan="4" class="text-right">TOTAL GASTOS:</td>
                <td class="text-right">-${{ number_format($totalExpenses, 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    {{-- Productos Vendidos --}}
    <div class="section-title">Productos Vendidos</div>
    <table class="small-table">
        <thead>
            <tr>
                <th style="width: 50%;">Producto</th>
                <th style="width: 15%; text-align: center;">Cantidad</th>
                <th style="width: 15%; text-align: center;">Ventas</th>
                <th style="width: 20%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($productsSold as $productData)
                <tr>
                    <td>
                        <strong>{{ $productData['product']->name ?? 'N/A' }}</strong>
                        @if ($productData['product']->sku)
                            <br><small style="color: #725C3A;">{{ $productData['product']->sku }}</small>
                        @endif
                    </td>
                    <td class="text-center">
                        {{ number_format($productData['quantity'], 2) }}
                        <small style="color: #725C3A;">{{ $productData['product']->unit->abbreviation ?? '' }}</small>
                    </td>
                    <td class="text-center">
                        {{ $productData['transactions'] }}
                    </td>
                    <td class="text-right">
                        ${{ number_format($productData['total'], 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay productos vendidos
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if (count($productsSold) > 0)
            <tfoot>
                <tr style="background-color: #809671; color: white; font-weight: bold;">
                    <td colspan="3" class="text-right">TOTAL:</td>
                    <td class="text-right">${{ number_format(array_sum(array_column($productsSold, 'total')), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- Detalle de Ventas --}}
    <div class="section-title">Detalle de Ventas</div>
    <table class="small-table">
        <thead>
            <tr>
                <th style="width: 10%;">Folio</th>
                <th style="width: 15%;">Hora</th>
                <th style="width: 25%;">Cliente</th>
                <th style="width: 20%;">Usuario</th>
                <th style="width: 15%; text-align: center;">Método Pago</th>
                <th style="width: 15%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sales as $sale)
                <tr>
                    <td>{{ $sale->id }}</td>
                    <td>{{ \Carbon\Carbon::parse($sale->created_at)->format('H:i:s') }}</td>
                    <td>{{ $sale->customer_info['name'] ?? 'Público General' }}</td>
                    <td>{{ $sale->user->name ?? 'N/A' }}</td>
                    <td class="text-center">
                        @if ($sale->payment_method === 'cash')
                            Efectivo
                        @elseif($sale->payment_method === 'card')
                            Tarjeta
                        @elseif($sale->payment_method === 'transfer')
                            Transferencia
                        @else
                            {{ $sale->payment_method ?? 'N/A' }}
                        @endif
                    </td>
                    <td class="text-right">${{ number_format($sale->total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay ventas registradas
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
