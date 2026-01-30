<?php

return [
    'reports' => [
        'orders' => [
            'key' => 'orders',
            'label' => 'Orders',
            'route' => 'reports.orders',
            'filters' => ['branch', 'status', 'source', 'date', 'search'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'purchase-orders' => [
            'key' => 'purchase-orders',
            'label' => 'Purchase Orders',
            'route' => 'reports.purchase-orders',
            'filters' => ['supplier', 'status', 'date_range', 'search'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'expenses' => [
            'key' => 'expenses',
            'label' => 'Expenses',
            'route' => 'reports.expenses',
            'filters' => ['supplier', 'category', 'payment_status', 'date_range', 'search'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'costing' => [
            'key' => 'costing',
            'label' => 'Costing',
            'route' => 'reports.costing',
            'filters' => ['menu_item', 'category'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'sales' => [
            'key' => 'sales',
            'label' => 'Sales',
            'route' => 'reports.sales',
            'filters' => ['branch', 'status', 'date_range'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'inventory' => [
            'key' => 'inventory',
            'label' => 'Inventory',
            'route' => 'reports.inventory',
            'filters' => ['branch', 'category', 'search', 'low_stock'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'payables' => [
            'key' => 'payables',
            'label' => 'Payables (AP)',
            'route' => 'reports.payables',
            'filters' => ['aging', 'invoice_register', 'payment_register', 'supplier', 'date_range'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
        'receivables' => [
            'key' => 'receivables',
            'label' => 'Receivables (AR)',
            'route' => 'reports.receivables',
            'filters' => ['branch', 'customer', 'status', 'date_range'],
            'outputs' => ['screen', 'print', 'csv', 'pdf'],
        ],
    ],
];
