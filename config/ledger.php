<?php

return [
    'accounts' => [
        'cash' => ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
        'petty_cash_asset' => ['code' => '1100', 'name' => 'Petty Cash', 'type' => 'asset'],
        'inventory_asset' => ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
        'ap_prepay' => ['code' => '1300', 'name' => 'Supplier Advances', 'type' => 'asset'],
        'tax_input' => ['code' => '1400', 'name' => 'Input Tax', 'type' => 'asset'],
        'ap_control' => ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
        'grni' => ['code' => '2100', 'name' => 'GRNI Clearing', 'type' => 'liability'],
        'cogs' => ['code' => '5000', 'name' => 'COGS', 'type' => 'expense'],
        'inventory_adjustment' => ['code' => '5100', 'name' => 'Inventory Adjustments', 'type' => 'expense'],
        'petty_cash_over_short' => ['code' => '5200', 'name' => 'Petty Cash Over/Short', 'type' => 'expense'],
        'expense_default' => ['code' => '6000', 'name' => 'General Expense', 'type' => 'expense'],
    ],
    'mappings' => [
        'ap_invoice_expense' => 'expense_default',
        'ap_invoice_inventory' => 'inventory_asset',
        'ap_invoice_ap' => 'ap_control',
        'ap_invoice_tax' => 'tax_input',
        'inventory_asset' => 'inventory_asset',
        'inventory_clearing' => 'grni',
        'inventory_adjustment' => 'inventory_adjustment',
        'inventory_cogs' => 'cogs',
        'ap_payment_cash' => 'cash',
        'ap_payment_prepay' => 'ap_prepay',
        'expense_cash' => 'cash',
        'petty_cash_asset' => 'petty_cash_asset',
        'petty_cash_issue_cash' => 'cash',
        'petty_cash_expense' => 'expense_default',
        'petty_cash_over_short' => 'petty_cash_over_short',
    ],
];
