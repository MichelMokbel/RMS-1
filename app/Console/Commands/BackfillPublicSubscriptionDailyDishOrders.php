<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Pricing\MealPlanPricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPublicSubscriptionDailyDishOrders extends Command
{
    protected $signature = 'orders:backfill-public-subscription-daily-dish
        {--dry-run : Do not write, only report}
        {--status=Draft : Comma-separated order statuses to include}
        {--order-id=* : Restrict the backfill to one or more specific order ids}';

    protected $description = 'Backfill draft public subscription daily-dish orders so totals and appetizer quantities match the selected meals per day';

    public function handle(MealPlanPricingService $pricingService): int
    {
        $statuses = collect(explode(',', (string) $this->option('status')))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();

        if ($statuses === []) {
            $this->error('At least one status is required.');

            return Command::FAILURE;
        }

        $orderIds = collect((array) $this->option('order-id'))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->values()
            ->all();

        $orders = Order::query()
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->where('source', 'Subscription')
            ->where('is_daily_dish', 1)
            ->whereIn('status', $statuses)
            ->when($orderIds !== [], fn ($query) => $query->whereIn('id', $orderIds))
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No matching subscription daily-dish orders found.');

            return Command::SUCCESS;
        }

        $planMealsByOrderId = DB::table('meal_plan_request_orders as mpro')
            ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
            ->whereIn('mpro.order_id', $orders->pluck('id'))
            ->pluck('mpr.plan_meals', 'mpro.order_id');

        $defaultAppetizerMenuItemId = $this->resolveDefaultAppetizerMenuItemId();
        $dryRun = (bool) $this->option('dry-run');

        $summary = [
            'matched' => $orders->count(),
            'updated' => 0,
            'unchanged' => 0,
            'created_appetizer' => 0,
            'skipped_missing_plan' => 0,
            'skipped_missing_main' => 0,
            'skipped_missing_appetizer_config' => 0,
        ];

        foreach ($orders as $order) {
            $mainQty = round((float) $order->items->where('role', 'main')->sum('quantity'), 3);
            if ($mainQty <= 0) {
                $summary['skipped_missing_main']++;
                $this->warn("Skipping order {$order->order_number}: no main quantity found.");
                continue;
            }

            $planMeals = $planMealsByOrderId->get($order->id);
            $planPrice = $pricingService->planPriceForKey($planMeals !== null ? (string) (int) $planMeals : null);
            if ($planPrice === null) {
                $summary['skipped_missing_plan']++;
                $this->warn("Skipping order {$order->order_number}: no linked meal plan price found.");
                continue;
            }

            $expectedTotal = round($planPrice * $mainQty, 3);
            $appetizerItems = $order->items->where('role', 'appetizer')->values();
            $primaryAppetizer = $appetizerItems->first();

            $needsAppetizer = $primaryAppetizer === null;
            if ($needsAppetizer && ! $defaultAppetizerMenuItemId) {
                $summary['skipped_missing_appetizer_config']++;
                $this->warn("Skipping order {$order->order_number}: missing default appetizer item configuration.");
                continue;
            }

            $orderChanged = round((float) $order->total_amount, 3) !== $expectedTotal
                || round((float) $order->total_before_tax, 3) !== $expectedTotal;
            $appetizerChanged = $primaryAppetizer === null
                || round((float) $primaryAppetizer->quantity, 3) !== $mainQty
                || round((float) $primaryAppetizer->unit_price, 3) !== 0.0
                || round((float) $primaryAppetizer->line_total, 3) !== 0.0;

            if (! $orderChanged && ! $appetizerChanged) {
                $summary['unchanged']++;
                continue;
            }

            if (! $dryRun) {
                DB::transaction(function () use ($order, $expectedTotal, $mainQty, $primaryAppetizer, $defaultAppetizerMenuItemId, $appetizerItems, &$summary): void {
                    $order->forceFill([
                        'total_before_tax' => $expectedTotal,
                        'total_amount' => $expectedTotal,
                    ])->save();

                    if ($primaryAppetizer) {
                        $primaryAppetizer->forceFill([
                            'quantity' => $mainQty,
                            'unit_price' => 0,
                            'discount_amount' => 0,
                            'line_total' => 0,
                        ])->save();
                    } else {
                        OrderItem::create([
                            'order_id' => $order->id,
                            'menu_item_id' => $defaultAppetizerMenuItemId,
                            'description_snapshot' => $this->buildAppetizerDescription((int) $defaultAppetizerMenuItemId),
                            'quantity' => $mainQty,
                            'unit_price' => 0,
                            'discount_amount' => 0,
                            'line_total' => 0,
                            'status' => 'Pending',
                            'sort_order' => (int) $order->items->max('sort_order') + 1,
                            'role' => 'appetizer',
                        ]);
                        $summary['created_appetizer']++;
                    }

                    $appetizerItems
                        ->slice(1)
                        ->each(function (OrderItem $item): void {
                            $item->forceFill([
                                'quantity' => 0,
                                'unit_price' => 0,
                                'discount_amount' => 0,
                                'line_total' => 0,
                            ])->save();
                        });
                });
            }

            $summary['updated']++;
        }

        $this->info('Matched: '.$summary['matched']);
        $this->info('Updated: '.$summary['updated'].($dryRun ? ' (dry run)' : ''));
        $this->info('Unchanged: '.$summary['unchanged']);
        $this->info('Created appetizer lines: '.$summary['created_appetizer']);
        $this->info('Skipped missing plan: '.$summary['skipped_missing_plan']);
        $this->info('Skipped missing main qty: '.$summary['skipped_missing_main']);
        $this->info('Skipped missing appetizer config: '.$summary['skipped_missing_appetizer_config']);

        return Command::SUCCESS;
    }

    private function resolveDefaultAppetizerMenuItemId(): ?int
    {
        $code = trim((string) config('subscriptions.default_appetizer_code', ''));
        if ($code === '') {
            return null;
        }

        return MenuItem::query()
            ->where('code', $code)
            ->where('is_active', 1)
            ->value('id');
    }

    private function buildAppetizerDescription(int $menuItemId): string
    {
        $menuItem = MenuItem::query()->find($menuItemId);

        return trim('Daily Dish (Appetizer) - '.(($menuItem?->code ?? '').' '.($menuItem?->name ?? '')));
    }
}
