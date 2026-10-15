@extends('pdf.layout')
@use('App\Reports\Format', 'F')

@php
    $date = $order->production_date ? \Carbon\Carbon::parse($order->production_date)->locale('es')->translatedFormat('l j \d\e F \d\e Y') : '—';
    $blank = '<span class="blank-line"></span>';
    $variance = function (?float $v) {
        if ($v === null) return '—';
        $cls = abs($v) < 0.0005 ? '' : ($v < 0 ? 'down' : 'up');
        return '<span class="' . $cls . '">' . ($v > 0 ? '+' : '') . F::pct($v) . '</span>';
    };
@endphp

@section('title', 'Hoja de producción ' . $order->folio)
@section('document-title', 'Hoja de producción')

@section('styles')
    <style>
        body { padding: 0; margin: 14px 18px 40px; font-size: 10pt; }
        .header { margin-bottom: 10px; padding-bottom: 10px; }
        .folio { font-size: 20pt; font-weight: bold; color: #0F172A; }
        .meta { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        .meta tr, table.meta tbody tr:nth-child(even) { background: none; }
        .meta td { border: none; padding: 2px 0; font-size: 10pt; vertical-align: top; }
        .meta .k { color: #725C3A; font-weight: bold; width: 105px; white-space: nowrap; }
        .box { background: #F4F1EA; border-radius: 5px; padding: 8px 12px; margin-bottom: 8px; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 9pt; font-weight: bold; background: #E5E0D8; color: #4F7A3A; }
        .status-draft { background: #FBEFD5; color: #8A6A2E; }
        .status-cancelled { background: #F6DCD7; color: #A65A4D; }
        h2.step { font-size: 12.5pt; color: #4F7A3A; margin: 14px 0 0; }
        h2.step .n { color: #725C3A; margin-right: 4px; }
        .hint { font-size: 8.5pt; color: #725C3A; margin: 2px 0 0; }
        table { margin-top: 6px; margin-bottom: 8px; }
        table th { font-size: 9pt; padding: 6px; }
        table td { font-size: 10pt; padding: 7px 6px; }
        .qty { font-size: 11.5pt; font-weight: bold; text-align: right; white-space: nowrap; }
        .check { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #333; border-radius: 2px; }
        .blank-line { display: inline-block; width: 80px; border-bottom: 1px solid #555; height: 14px; }
        .write td { height: 22px; }
        tr.short td { background-color: #F6DCD7 !important; }
        .short-tag { font-size: 8pt; color: #A65A4D; font-weight: bold; }
        .up { color: #4F7A3A; font-weight: bold; }
        .down { color: #A65A4D; font-weight: bold; }
        .muted { color: #888; }
        .small { font-size: 8.5pt; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 4px -6px 8px; }
        .summary td { border: none; background: #F4F1EA; border-radius: 5px; padding: 8px 10px; }
        .summary .label { font-size: 8pt; color: #725C3A; font-weight: bold; text-transform: uppercase; }
        .summary .value { font-size: 15pt; font-weight: bold; color: #0F172A; }
        .notes-lines div { border-bottom: 1px solid #999; height: 22px; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 36px; }
        .signatures td { border: none; width: 33%; padding: 0 12px; vertical-align: top; }
        .signature-line { border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 9pt; }
        .signature-name { font-weight: bold; }
    </style>
@endsection

@section('content')
    <table class="meta">
        <tr>
            <td style="width: 55%">
                <div class="folio">{{ $order->folio }}</div>
                <span class="status {{ $draft ? 'status-draft' : ($status?->value === 'cancelled' ? 'status-cancelled' : '') }}">{{ $status?->label() ?? $order->status }}</span>
            </td>
            <td>
                <table class="meta">
                    <tr><td class="k">Fecha</td><td>{{ ucfirst($date) }}</td></tr>
                    <tr><td class="k">Sucursal</td><td>{{ $order->location?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Responsable</td><td>{{ $order->responsible?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Fórmula</td><td>{{ $order->formula ? $order->formula->name . ' (v' . $order->formula->version . ')' : 'Sin fórmula' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if($draft)
        <div class="box small">
            Saca del almacén los insumos de la lista y palomea cada uno. Al terminar anota lo que realmente se produjo,
            lo que se usó de cada insumo y la merma, y entrega la hoja para capturarla en la app.
            @if($short_count > 0)
                <br><strong class="down">{{ $short_count }} {{ $short_count === 1 ? 'insumo no alcanza' : 'insumos no alcanzan' }} con la existencia de la sucursal (marcados en rojo).</strong>
            @endif
        </div>
    @endif

    {{-- 1. Qué producir ─────────────────────────────────────────────────── --}}
    <h2 class="step"><span class="n">1.</span>{{ $closed ? 'Producido contra lo esperado' : 'Qué producir' }}</h2>
    @if(empty($outputs))
        <div class="hint">La orden no tiene productos a obtener.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 13%">Código</th>
                    <th>Producto</th>
                    <th style="width: 8%">Unidad</th>
                    <th class="text-right" style="width: 13%">Esperado</th>
                    <th class="text-right" style="width: 15%">{{ $closed ? 'Producido' : 'Producido real' }}</th>
                    @if($closed)<th class="text-right" style="width: 11%">Diferencia</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach($outputs as $o)
                    <tr>
                        <td>{{ $o['code'] }}</td>
                        <td><strong>{{ $o['name'] }}</strong>@if($o['notes'])<br><span class="small">{{ $o['notes'] }}</span>@endif</td>
                        <td>{{ $o['unit'] }}</td>
                        {{-- En borrador lo planeado es lo esperado; ya cerrada, solo lo que dio la fórmula. --}}
                        <td class="qty">{{ F::qty($closed ? $o['expected'] : ($o['expected'] ?? $o['quantity'])) }}</td>
                        @if($closed)
                            <td class="qty">{{ F::qty($o['quantity']) }}</td>
                            <td class="text-right">{!! $variance($o['variance']) !!}</td>
                        @else
                            <td class="text-right">{!! $blank !!}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- 2. Insumos ───────────────────────────────────────────────────────── --}}
    <h2 class="step"><span class="n">2.</span>{{ $closed ? 'Insumos usados' : 'Insumos a sacar del almacén' }}</h2>
    @if(empty($consumptions))
        <div class="hint">La orden no tiene insumos.</div>
    @else
        <table>
            <thead>
                <tr>
                    @if(!$closed)<th style="width: 4%"></th>@endif
                    <th style="width: 13%">Código</th>
                    <th>Insumo</th>
                    <th style="width: 8%">Unidad</th>
                    @if($closed)
                        <th class="text-right" style="width: 13%">Esperado</th>
                        <th class="text-right" style="width: 13%">Usado</th>
                        <th class="text-right" style="width: 11%">Diferencia</th>
                    @else
                        <th class="text-right" style="width: 13%">Sacar</th>
                        <th class="text-right" style="width: 14%">Hay en sucursal</th>
                        <th class="text-right" style="width: 14%">Usado real</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($consumptions as $c)
                    <tr class="{{ $c['short'] ? 'short' : '' }}">
                        @if(!$closed)<td class="text-center"><span class="check"></span></td>@endif
                        <td>{{ $c['code'] }}</td>
                        <td>
                            <strong>{{ $c['name'] }}</strong>
                            @if($c['short'])<br><span class="short-tag">No alcanza la existencia</span>@endif
                            @if($c['notes'])<br><span class="small">{{ $c['notes'] }}</span>@endif
                        </td>
                        <td>{{ $c['unit'] }}</td>
                        @if($closed)
                            <td class="qty">{{ F::qty($c['expected']) }}</td>
                            <td class="qty">{{ F::qty($c['quantity']) }}</td>
                            <td class="text-right">{!! $variance($c['variance']) !!}</td>
                        @else
                            <td class="qty">{{ F::qty($c['quantity']) }}</td>
                            <td class="text-right nowrap">{{ F::qty($c['stock']) }} {{ $c['stock_unit'] }}</td>
                            <td class="text-right">{!! $blank !!}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- 3. Merma ─────────────────────────────────────────────────────────── --}}
    <h2 class="step"><span class="n">3.</span>Merma</h2>
    @if($closed)
        <table class="summary">
            <tr>
                <td>
                    <div class="label">Total usado</div>
                    <div class="value">{{ F::qty((float) $order->total_consumed_quantity) }}</div>
                </td>
                <td>
                    <div class="label">Total producido</div>
                    <div class="value">{{ F::qty((float) $order->total_produced_quantity) }}</div>
                </td>
                <td>
                    <div class="label">Merma del proceso</div>
                    <div class="value {{ (float) $order->waste_percentage > 0 ? 'down' : '' }}">{{ F::pct((float) $order->waste_percentage / 100) }}</div>
                </td>
            </tr>
        </table>
        <div class="hint">La merma del proceso compara lo usado contra lo producido sumando cantidades, como en la app.</div>
    @endif

    @if(!empty($wastes))
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="text-right" style="width: 15%">Cantidad</th>
                    <th style="width: {{ $closed ? '45%' : '22%' }}">{{ $closed ? 'Detalle' : 'Motivo' }}</th>
                    @unless($closed)<th style="width: 25%">Notas</th>@endunless
                </tr>
            </thead>
            <tbody>
                @foreach($wastes as $w)
                    <tr>
                        <td>{{ $w['name'] }}</td>
                        <td class="qty">{{ F::qty($w['quantity']) }} {{ $w['unit'] }}</td>
                        @if($closed)
                            <td class="small">{{ $w['notes'] }}</td>
                        @else
                            <td>{{ $w['reason'] }}</td>
                            <td class="small">{{ $w['notes'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif($closed)
        <div class="hint">No se registró merma de producto.</div>
    @endif

    @unless($closed)
        <div class="hint">Anota aquí la merma de producto (lo que se tiró, se dañó o se derramó):</div>
        <table>
            <thead><tr><th>Producto</th><th style="width: 18%">Cantidad</th><th style="width: 30%">Motivo</th></tr></thead>
            <tbody>
                @for($i = 0; $i < 4; $i++)
                    <tr class="write"><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>
    @endunless

    @if(!empty($packagings))
        <h2 class="step"><span class="n">4.</span>Empaque</h2>
        <table>
            <thead>
                <tr>
                    <th>Producto terminado</th>
                    <th class="text-right" style="width: 14%">Empacado</th>
                    <th>Granel usado</th>
                    <th style="width: 16%">Fecha</th>
                </tr>
            </thead>
            <tbody>
                @foreach($packagings as $p)
                    <tr>
                        <td>{{ $p['name'] }}</td>
                        <td class="qty">{{ F::qty($p['quantity']) }} {{ $p['unit'] }}</td>
                        <td>{{ $p['source'] ? $p['source'] . ' · ' . F::qty($p['source_quantity']) . ' ' . $p['source_unit'] : '—' }}</td>
                        <td class="nowrap">{{ F::dateTime($p['packed_at']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($order->notes)
        <div class="box small"><strong>Notas de la orden:</strong> {{ $order->notes }}</div>
    @endif

    @unless($closed)
        <div class="hint" style="margin-top: 10px">Observaciones:</div>
        <div class="notes-lines"><div></div><div></div></div>
    @endunless

    <table class="signatures">
        <tr>
            <td><div class="signature-line"><div class="signature-name">Entregó almacén</div>Nombre y firma</div></td>
            <td><div class="signature-line"><div class="signature-name">Produjo</div>{{ $order->responsible?->name ?? 'Nombre y firma' }}</div></td>
            <td><div class="signature-line"><div class="signature-name">Revisó</div>Nombre y firma</div></td>
        </tr>
    </table>
@endsection
