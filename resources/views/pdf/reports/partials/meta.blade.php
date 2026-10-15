{{-- Renglón de contexto del reporte: sucursal y periodo (o fecha de corte). --}}
<table class="report-meta">
    <tr>
        <td>
            <span class="k">Sucursal:</span> {{ $scope->locationLabel() }}
            &nbsp;&nbsp;·&nbsp;&nbsp;
            <span class="k">{{ $periodLabel ?? 'Periodo' }}:</span> {{ $periodValue }}
            @isset($compareLabel)
                &nbsp;&nbsp;·&nbsp;&nbsp;<span class="k">Comparado con:</span> {{ $compareLabel }}
            @endisset
        </td>
        <td class="right nowrap" style="width: 150px">Generado el {{ now()->format('d/m/Y H:i') }}</td>
    </tr>
</table>
