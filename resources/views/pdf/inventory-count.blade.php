@extends('pdf.layout')

@section('title', 'Inventario - ' . ($inventoryCount->location->name ?? 'N/A'))

@section('document-title', 'Inventario - ' . ($inventoryCount->location->name ?? 'N/A'))

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
            width: 100px;
        }

        .info-grid-value {
            color: #333;
        }
    </style>
@endsection

@section('content')
    {{-- Información General en 2 Columnas --}}
    <div class="info-section">
        <div class="info-grid">
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Fecha:</span>
                    <span
                        class="info-grid-value">{{ \Carbon\Carbon::parse($inventoryCount->count_date)->format('d/m/Y') }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Usuario:</span>
                    <span class="info-grid-value">{{ $inventoryCount->user->name ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Estado:</span>
                    <span class="info-grid-value">
                        @php
                            $statusLabels = [
                                'planning' => 'Planificación',
                                'counting' => 'Contando',
                                'completed' => 'Completado',
                                'cancelled' => 'Cancelado',
                            ];
                            $statusClass = [
                                'planning' => 'badge-info',
                                'counting' => 'badge-warning',
                                'completed' => 'badge-success',
                                'cancelled' => 'badge-danger',
                            ];
                        @endphp
                        <span class="badge {{ $statusClass[$inventoryCount->status] ?? 'badge-info' }}">
                            {{ $statusLabels[$inventoryCount->status] ?? $inventoryCount->status }}
                        </span>
                    </span>
                </div>
                @if ($inventoryCount->status === 'completed')
                    <div class="info-grid-cell">
                        @php
                            $totalProducts = $inventoryCount->details->count();
                            $withDifference = $inventoryCount->details->where('difference', '!=', 0)->count();
                        @endphp
                        <span class="info-grid-label">Productos:</span>
                        <span class="info-grid-value">{{ $totalProducts }} ({{ $withDifference }} diferencias)</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabla de Productos --}}
    <table>
        <thead>
            <tr>
                <th style="width: 40%;">Producto</th>
                <th style="width: 15%; text-align: center;">Stock Sistema</th>
                <th style="width: 15%; text-align: center;">Stock Físico</th>
                <th style="width: 15%; text-align: center;">Diferencia</th>
                <th style="width: 15%; text-align: center;">Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse($inventoryCount->details as $index => $detail)
                <tr>
                    <td>
                        <strong>{{ $detail->product->name ?? 'N/A' }}</strong>
                        @if ($detail->product->sku)
                            <br><small style="color: #725C3A;">{{ $detail->product->sku }}</small>
                        @endif
                    </td>
                    <td class="text-center">
                        {{ number_format($detail->system_quantity, 2) }}
                        <small style="color: #725C3A;">{{ $detail->product->unit->abbreviation ?? '' }}</small>
                    </td>
                    <td class="text-center">
                        @if ($detail->counted_quantity !== null)
                            {{ number_format($detail->counted_quantity, 2) }}
                            <small style="color: #725C3A;">{{ $detail->product->unit->abbreviation ?? '' }}</small>
                        @else
                            <span style="color: #B3B792;">-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($detail->difference !== null && $detail->difference != 0)
                            <span class="{{ $detail->difference > 0 ? 'text-success' : 'text-danger' }}">
                                {{ $detail->difference > 0 ? '+' : '' }}{{ number_format($detail->difference, 2) }}
                            </span>
                        @elseif($detail->difference === 0)
                            <span class="text-success">0</span>
                        @else
                            <span style="color: #B3B792;">-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($detail->counted_quantity === null)
                            <span class="badge badge-warning">Pendiente</span>
                        @elseif($detail->difference == 0)
                            <span class="badge badge-success">✓</span>
                        @else
                            <span class="badge badge-danger">!</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay productos registrados
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
