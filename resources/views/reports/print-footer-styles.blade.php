        @page {
            margin: 8mm 10mm 0 10mm;
        }
        @media print {
            body {
                margin: 0 !important;
                padding: 0 0 14mm 0 !important;
            }
        }
        .print-footer {
            position: fixed;
            bottom: 2mm;
            left: 0;
            right: 0;
            font-size: 8px;
            color: #333;
            border-top: 1px solid #999;
            padding: 3px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .print-footer .footer-left {
            flex: 1;
            text-align: left;
        }
