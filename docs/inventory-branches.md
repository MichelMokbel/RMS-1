# Inventory Availability & Transfers

## Availability
- Inventory items are global in `inventory_items`.
- Branch availability is represented by `inventory_stocks`.
- If a row exists in `inventory_stocks` for `(inventory_item_id, branch_id)`, the item is available in that branch.
- Adding availability creates a stock row with `current_stock = 0`.

## Transfers
- Transfers move quantity between branches without changing global stock.
- Each transfer creates:
  - One `inventory_transfers` record
  - One or more `inventory_transfer_lines` records
  - Two `inventory_transactions` per line (out from source, in to destination)

## Ledger (Option B)
- Transfers post a balanced subledger entry:
  - Debit: inventory asset (destination branch)
  - Credit: inventory asset (source branch)
- Entry is stored as `source_type = inventory_transfer`.

## Notes
- Stock is stored in package units (same unit as inventory transactions).
- Transfers respect `inventory.allow_negative_stock`.
- Destination availability is created automatically if missing.
