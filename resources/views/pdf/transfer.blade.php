@extends('pdf.layout')

@php
    $qty = fn($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $status = $transfer->status?->value ?? $transfer->status;
    $shipped = in_array($status, ['in_transit', 'completed'], true);
    $received = $status === 'completed';
    $statusClass = [
        'pending' => 'badge-warning', 'approved' => 'badge-info', 'in_transit' => 'badge-warning',
        'completed' => 'badge-success', 'rejected' => 'badge-danger', 'cancelled' => 'badge-danger',
    ][$status] ?? 'badge-info';
    $date = fn($v) => $v ? \Carbon\Carbon::parse($v)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—';
@endphp

@section('title', 'Remisión ' . $transfer->transfer_number)
@section('document-title', 'Remisión ' . $transfer->transfer_number)

@section('styles')
    <style>
        .route { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
        .route td { border: none; padding: 0; vertical-align: top; }
        .place { background: #F4F1EA; border-radius: 5px; padding: 8px 10px; }
        .place-label { font-size: 8pt; color: #725C3A; font-weight: bold; text-transform: uppercase; }
        .place-name { font-size: 12pt; font-weight: bold; color: #0F172A; }
        .arrow { width: 40px; text-align: center; font-size: 18pt; color: #4F7A3A; padding-top: 8px; }
        .meta { width: 100%; border-collapse: collapse; margin: 0; }
        .meta td { border: none; padding: 2px 0; font-size: 9pt; }
        .meta .k { color: #725C3A; font-weight: bold; width: 95px; }
        .blank { color: #999; }
        .diff-row td { background: #FBEDEA !important; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 40px; }
        .signatures td { border: none; width: 33%; padding: 0 12px; vertical-align: top; }
        .signature-line { border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 8.5pt; }
        .signature-name { font-weight: bold; }
        .note { font-size: 9pt; margin-top: 6px; }
    </style>
@endsection

@section('content')
    <table class="route">
        <tr>
            <td class="place">
                <div class="place-label">Sale de</div>
                <div class="place-name">{{ $transfer->fromLocation?->name ?? '—' }}</div>
                <div style="font-size:8.5pt">{{ $transfer->fromLocation?->address }}</div>
            </td>
            <td class="arrow">&rarr;</td>
            <td class="place">
                <div class="place-label">Llega a</div>
                <div class="place-name">{{ $transfer->toLocation?->name ?? '—' }}</div>
                <div style="font-size:8.5pt">{{ $transfer->toLocation?->address }}</div>
            </td>
        </tr>
    </table>

    <table class="route">
        <tr>
            <td style="width: 50%">
                <table class="meta">
                    <tr><td class="k">Folio</td><td><strong>{{ $transfer->transfer_number }}</strong></td></tr>
                    <tr><td class="k">Estado</td><td><span class="badge {{ $statusClass }}">{{ $transfer->status?->label() ?? $status }}</span></td></tr>
                    <tr><td class="k">Solicitó</td><td>{{ $transfer->requestedByUser?->name ?? '—' }} · {{ $date($transfer->created_at) }}</td></tr>
                </table>
            </td>
            <td style="width: 50%">
                <table class="meta">
                    <tr><td class="k">Aprobó</td><td>{{ $people['approved_by'] ?? '—' }} · {{ $date($steps['approved_at'] ?? null) }}</td></tr>
                    <tr><td class="k">Envió</td><td>{{ $people['shipped_by'] ?? '—' }} · {{ $date($steps['shipped_at'] ?? null) }}</td></tr>
                    <tr><td class="k">Recibió</td><td>{{ $people['received_by'] ?? '—' }} · {{ $date($steps['received_at'] ?? null) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th style="width: 4%">#</th>
                <th style="width: 14%">Código</th>
                <th>Producto</th>
                <th style="width: 9%">Unidad</th>
                <th class="text-right" style="width: 10%">Solicitado</th>
                <th class="text-right" style="width: 10%">Enviado</th>
                <th class="text-right" style="width: 10%">Recibido</th>
                <th class="text-right" style="width: 10%">Diferencia</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfer->details as $i => $d)
                @php
                    $diff = $received && $d->quantity_received !== null && $d->quantity_shipped !== null
                        ? (float) $d->quantity_received - (float) $d->quantity_shipped
                        : null;
                @endphp
                <tr class="{{ $diff !== null && abs($diff) > 0.0001 ? 'diff-row' : '' }}">
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td>{{ $d->product?->code }}</td>
                    <td>
                        {{ $d->product?->name ?? 'Producto' }}
                        @if($d->package)<br><span style="font-size:8pt;color:#725C3A">{{ $d->package->package_name }}</span>@endif
                        @if($d->damage_report)<br><span style="font-size:8pt;color:#A65A4D">Daño: {{ $d->damage_report }}</span>@endif
                        @if($d->notes)<br><span style="font-size:8pt">{{ $d->notes }}</span>@endif
                    </td>
                    <td>{{ $d->unit?->abbreviation ?? $d->product?->unit?->abbreviation }}</td>
                    <td class="text-right">{{ $qty($d->quantity_requested) }}</td>
                    {{-- Sin enviar todavía: la columna queda para llenarse a mano al cargar. --}}
                    <td class="text-right">{!! $shipped ? e($qty($d->quantity_shipped)) : '<span class="blank">______</span>' !!}</td>
                    <td class="text-right">{!! $received ? e($qty($d->quantity_received)) : '<span class="blank">______</span>' !!}</td>
                    <td class="text-right {{ $diff !== null && $diff < 0 ? 'text-danger' : '' }}">
                        {{ $diff === null ? '' : ($diff > 0 ? '+' : '') . $qty($diff) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary-box">
        <div class="summary-title">Resumen</div>
        <div>{{ $transfer->details->count() }} {{ $transfer->details->count() === 1 ? 'producto' : 'productos' }}
            @if($received)
                @php $withDiff = $transfer->details->filter(fn($d) => $d->quantity_received !== null && abs((float) $d->quantity_received - (float) $d->quantity_shipped) > 0.0001)->count(); @endphp
                · {{ $withDiff === 0 ? 'Recibido completo, sin diferencias' : $withDiff . ' con diferencia entre lo enviado y lo recibido' }}
            @elseif($shipped)
                · En camino: el destino debe contar y anotar lo recibido
            @else
                · Pendiente de envío
            @endif
        </div>
        @if($transfer->notes)<div class="note"><strong>Notas:</strong> {{ $transfer->notes }}</div>@endif
        @if(!empty($steps['shipping_notes']))<div class="note"><strong>Notas de envío:</strong> {{ $steps['shipping_notes'] }}</div>@endif
        @if(!empty($steps['receiving_notes']))<div class="note"><strong>Notas de recepción:</strong> {{ $steps['receiving_notes'] }}</div>@endif
    </div>

    <table class="signatures">
        <tr>
            <td><div class="signature-line"><div class="signature-name">Entrega</div>{{ $people['shipped_by'] ?? $transfer->fromLocation?->name }}</div></td>
            <td><div class="signature-line"><div class="signature-name">Transporta</div>Nombre y firma</div></td>
            <td><div class="signature-line"><div class="signature-name">Recibe</div>{{ $people['received_by'] ?? $transfer->toLocation?->name }}</div></td>
        </tr>
    </table>
@endsection
