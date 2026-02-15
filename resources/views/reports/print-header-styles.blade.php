        .report-header { margin-bottom: 8px; color: #000; }
        .report-header-top { display: grid; grid-template-columns: 80px 1fr auto; align-items: start; gap: 10px; }
        .report-logo { width: 60px; height: 60px; object-fit: contain; display: block; }
        .report-company { text-align: center; }
        .report-company h2 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: 0.3px; }
        .report-company .address { margin-top: 2px; font-size: 11px; }
        .report-company .title { margin-top: 5px; font-size: 13px; font-weight: 700; text-transform: uppercase; }
        .report-meta { font-size: 11px; line-height: 1.45; text-align: right; white-space: nowrap; }
        .report-meta .row strong { font-weight: 700; }
        .report-header-bottom { margin-top: 6px; display: flex; justify-content: space-between; gap: 12px; font-size: 11px; }
        .report-header-bottom .left,
        .report-header-bottom .right { width: 49%; }
        .report-header-bottom .right { text-align: right; }
        .report-header-bottom .row { margin-bottom: 3px; white-space: nowrap; }
        @include('reports.print-footer-styles')
