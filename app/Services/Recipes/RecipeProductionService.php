<?php

namespace App\Services\Recipes;

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeProduction;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeProductionService
{
    public function __construct(
        protected InventoryStockService $stockService
    ) {
    }

    public function produce(Recipe $recipe, array $payload, int $userId): RecipeProduction
    {
        $recipe->loadMissing(['items.inventoryItem']);

        $producedQty = (float) ($payload['produced_quantity'] ?? 0);
        if (! $recipe->yieldIsValid()) {
            throw ValidationException::withMessages(['yield_quantity' => __('Recipe yield must be greater than zero.')]);
        }
        if ($producedQty <= 0) {
            throw ValidationException::withMessages(['produced_quantity' => __('Produced quantity must be greater than zero.')]);
        }
        if ($recipe->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => __('Recipe must have at least one ingredient.')]);
        }

        $factor = $producedQty / (float) $recipe->yield_quantity;

        return DB::transaction(function () use ($recipe, $payload, $userId, $factor) {
            // Lock recipe to prevent concurrent updates
            $lockedRecipe = Recipe::whereKey($recipe->id)->lockForUpdate()->firstOrFail();

            // Lock inventory items to check stock atomically
            $lockedItems = $lockedRecipe->items()->with('inventoryItem')->get();

            $strictStockCheck = array_key_exists('strict_stock_check', $payload)
                ? (bool) $payload['strict_stock_check']
                : true;

            $deductions = [];

            foreach ($lockedItems as $item) {
                /** @var InventoryItem|null $inv */
                $inv = $item->inventoryItem;
                if (! $inv) {
                    throw ValidationException::withMessages([
                        'inventory_item_id' => __("Inventory item for ingredient is missing."),
                    ]);
                }

                $requiredQty = (float) $item->quantity * $factor;
                $unitsPerPackage = (float) ($inv->units_per_package ?? 0);

                if ($item->quantity_type === 'package') {
                    $deduction = $requiredQty;
                } else {
                    if ($unitsPerPackage <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => __("Item :name has no units_per_package; cannot convert unit quantity to packages.", ['name' => $inv->name ?? $inv->id]),
                        ]);
                    }
                    $deduction = $requiredQty / $unitsPerPackage;
                }

                $intDeduction = (int) round($deduction);
                if (abs($deduction - $intDeduction) > 0.001) {
                    throw ValidationException::withMessages([
                        'quantity' => __("This recipe production would consume a fractional stock quantity for item :item. Adjust recipe quantities or units_per_package to align with stock tracking.", [
                            'item' => $inv->name ?? $inv->item_code ?? $inv->id,
                        ]),
                    ]);
                }

                $deductions[] = [
                    'inventory_item' => $inv,
                    'int_deduction' => $intDeduction,
                ];
            }

            // Stock availability check
            if ($strictStockCheck && ! config('inventory.allow_negative_stock', false)) {
                foreach ($deductions as $row) {
                    $inv = $row['inventory_item'];
                    $intDeduction = $row['int_deduction'];
                    $newStock = ($inv->current_stock ?? 0) - $intDeduction;
                    if ($newStock < 0) {
                        throw ValidationException::withMessages([
                            'quantity' => __("Insufficient stock for item :item.", [
                                'item' => $inv->name ?? $inv->item_code ?? $inv->id,
                            ]),
                        ]);
                    }
                }
            }

            // Create production record
            $production = RecipeProduction::create([
                'recipe_id' => $lockedRecipe->id,
                'produced_quantity' => $payload['produced_quantity'],
                'production_date' => $payload['production_date'] ?? now(),
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Deduct inventory
            foreach ($deductions as $row) {
                $inv = $row['inventory_item'];
                $intDeduction = $row['int_deduction'];

                if ($intDeduction === 0) {
                    continue;
                }

                $this->stockService->recordMovement(
                    $inv,
                    'out',
                    $intDeduction,
                    referenceType: 'recipe',
                    referenceId: $production->id,
                    notes: "Recipe {$lockedRecipe->name} production #{$production->id}",
                    userId: $userId
                );
            }

            return $production->fresh(['recipe']);
        });
    }
}

