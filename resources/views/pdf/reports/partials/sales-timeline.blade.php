{{-- Tabla de ventas por día, mes u hora con una barra proporcional. --}}
@use('App\Reports\Format', 'F')
<h2 class="section">{{ $title }}</h2>
@if(empty($rows))
    <div class="empty">Sin ventas en el periodo.</div>
@else
    <table>
        <thead><tr><th>{{ $column }}</th><th class="text-right">Tickets</th><th class="text-right">Ventas</th><th class="bar-cell"></th></tr></thead>
        <tbody>
            @foreach($rows as $r)
                <tr class="{{ $r['tickets'] === 0 ? 'row-muted' : '' }}">
                    <td class="nowrap">{{ $r['label'] }}</td>
                    <td class="text-right">{{ number_format($r['tickets']) }}</td>
                    <td class="text-right">{{ F::money($r['total']) }}</td>
                    <td class="bar-cell">@if($r['total'] > 0)<div class="bar" style="width: {{ max(1, round($r['total'] / $max * 100)) }}%"></div>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
