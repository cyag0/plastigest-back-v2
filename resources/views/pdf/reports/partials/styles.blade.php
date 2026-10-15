{{-- Estilos comunes de los reportes del dueño (se suman a pdf.layout). --}}
<style>
    body { padding: 0; margin: 14px 18px 40px; font-size: 9pt; }
    .header { margin-bottom: 10px; padding-bottom: 10px; }
    table { margin-top: 6px; margin-bottom: 12px; }
    table th { padding: 5px 6px; font-size: 8pt; }
    table td { padding: 4px 6px; font-size: 8.5pt; }

    .report-meta { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
    .report-meta td { border: none; padding: 0; font-size: 9pt; vertical-align: top; }
    .report-meta .k { color: #725C3A; font-weight: bold; }
    .report-meta .right { text-align: right; color: #725C3A; }

    .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px; }
    .kpis td { border: none; background: #F4F1EA; border-radius: 5px; padding: 8px 10px; vertical-align: top; }
    .kpi-label { font-size: 7.5pt; color: #725C3A; font-weight: bold; text-transform: uppercase; }
    .kpi-value { font-size: 14pt; font-weight: bold; color: #0F172A; margin-top: 2px; }
    .kpi-note { font-size: 7.5pt; color: #555; margin-top: 2px; }
    .kpis td.alert { background: #FBEDEA; }
    .kpis td.alert .kpi-value { color: #A65A4D; }

    h2.section { font-size: 11pt; color: #4F7A3A; margin: 14px 0 2px; padding-bottom: 3px; border-bottom: 1px solid #E5E0D8; }
    .section-note { font-size: 8pt; color: #725C3A; margin: 2px 0 0; }

    .up { color: #4F7A3A; font-weight: bold; }
    .down { color: #A65A4D; font-weight: bold; }
    .muted { color: #888; }
    .small { font-size: 7.5pt; }
    .nowrap { white-space: nowrap; }

    table tbody tr.row-danger td { background-color: #F6DCD7; }
    table tbody tr.row-warning td { background-color: #FBEFD5; }
    table tbody tr.row-muted td { color: #777; }
    table tr.total-row td { font-weight: bold; background-color: #E5E0D8; }

    .bar-cell { width: 90px; }
    .bar { height: 7px; background: #A9CE93; border-radius: 2px; }

    .two-cols { width: 100%; border-collapse: collapse; margin: 0; }
    .two-cols > tbody > tr > td, .two-cols > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }
    .two-cols .gap { width: 14px; }

    .empty { color: #888; font-style: italic; padding: 6px 0 10px; }
    .page-break { page-break-before: always; }
    .footnote { font-size: 7.5pt; color: #725C3A; margin-top: 6px; }
</style>
