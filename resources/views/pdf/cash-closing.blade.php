@extends('pdf.layout')

@php
    $fmt = fn($v) => '$' . number_format((float) ($v ?? 0), 2);
    $diff = (float) ($closing->difference ?? 0);
@endphp

@section('title', 'Corte de Caja - ' . \Carbon\Carbon::parse($closing->closing_date)->format('d/m/Y'))

@section('document-title', 'Corte de Caja')

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
                    <span class="info-grid-value">{{ \Carbon\Carbon::parse($closing->closing_date)->format('d/m/Y') }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Sucursal:</span>
                    <span class="info-grid-value">{{ $closing->location->name ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="info-grid-row">
                <div class="info-grid-cell">
                    <span class="info-grid-label">Cerrado por:</span>
                    <span class="info-grid-value">{{ $closing->user->name ?? 'N/A' }}</span>
                </div>
                <div class="info-grid-cell">
                    <span class="info-grid-label">Movimientos:</span>
                    <span class="info-grid-value">{{ $closing->movements_count ?? 0 }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Resumen del día --}}
    <div class="summary-card">
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell">
                    <div class="summary-label">Saldo inicial</div>
                    <div class="summary-value">{{ $fmt($closing->opening_balance) }}</div>
                </div>
                <div class="summary-cell">
                    <div class="summary-label">Ingresos</div>
                    <div class="summary-value" style="color: #16A34A;">{{ $fmt($closing->total_income) }}</div>
                </div>
                <div class="summary-cell">
                    <div class="summary-label">Egresos</div>
                    <div class="summary-value" style="color: #DC2626;">-{{ $fmt($closing->total_expense) }}</div>
                </div>
                <div class="summary-cell" style="background-color: #DCFCE7; border-radius: 5px;">
                    <div class="summary-label" style="color: #16A34A;">Saldo esperado</div>
                    <div class="summary-value" style="color: #16A34A;">{{ $fmt($closing->expected_balance) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Saldo por método de pago --}}
    <div class="section-title">Saldo por Método de Pago</div>
    <table class="small-table">
        <thead>
            <tr>
                <th style="width: 25%;">Efectivo</th>
                <th style="width: 25%;">Tarjeta</th>
                <th style="width: 25%;">Transferencia</th>
                <th style="width: 25%;">Otro</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">{{ $fmt($closing->total_cash) }}</td>
                <td class="text-center">{{ $fmt($closing->total_card) }}</td>
                <td class="text-center">{{ $fmt($closing->total_transfer) }}</td>
                <td class="text-center">{{ $fmt($closing->total_other) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Conteo físico --}}
    @if($closing->physical_count !== null)
        <div class="section-title">Conteo Físico</div>
        <table class="small-table">
            <tbody>
                <tr>
                    <td style="width: 50%;">Efectivo contado</td>
                    <td class="text-right">{{ $fmt($closing->physical_count) }}</td>
                </tr>
                <tr>
                    <td>Diferencia</td>
                    <td class="text-right" style="color: {{ $diff == 0 ? '#16A34A' : ($diff > 0 ? '#2563EB' : '#DC2626') }}; font-weight: bold;">
                        {{ $diff >= 0 ? '+' : '' }}{{ $fmt($diff) }}
                    </td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Detalle de movimientos --}}
    <div class="section-title">Movimientos del Día</div>
    <table class="small-table">
        <thead>
            <tr>
                <th style="width: 12%;">Tipo</th>
                <th style="width: 38%;">Concepto</th>
                <th style="width: 20%;">Método</th>
                <th style="width: 15%;">Usuario</th>
                <th style="width: 15%; text-align: right;">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse($movements as $movement)
                <tr>
                    <td>{{ $movement->type_label }}</td>
                    <td>{{ $movement->concept }}</td>
                    <td>{{ $movement->payment_method_label }}</td>
                    <td>{{ $movement->user->name ?? 'N/A' }}</td>
                    <td class="text-right" style="color: {{ $movement->type === 'expense' ? '#DC2626' : '#16A34A' }};">
                        {{ $movement->type === 'expense' ? '-' : '+' }}{{ $fmt($movement->amount) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center" style="padding: 20px; color: #725C3A;">
                        No hay movimientos registrados para este día
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Notas --}}
    @if($closing->notes)
        <div class="info-section" style="margin-top: 15px;">
            <strong style="color: #725C3A;">Notas:</strong>
            <div style="margin-top: 5px;">{{ $closing->notes }}</div>
        </div>
    @endif
@endsection
