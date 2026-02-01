<?php

namespace App\Support\Orders;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OrderCreateRules
{
    public function rules(): array
    {
        $branchRule = ['required', 'integer', 'min:1'];
        if (Schema::hasTable('branches')) {
            $exists = Rule::exists('branches', 'id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $exists = $exists->where('is_active', 1);
            }
            $branchRule[] = $exists;
        }

        return [
            'branch_id' => $branchRule,
            'source' => ['required', 'in:POS,Phone,WhatsApp,Subscription,Backoffice,Website'],
            'is_daily_dish' => ['boolean'],
            'type' => ['required', 'in:DineIn,Takeaway,Delivery,Pastry'],
            'status' => ['required', 'in:Draft,Confirmed,InProduction,Ready,OutForDelivery,Delivered,Cancelled'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name_snapshot' => ['nullable', 'string', 'max:255'],
            'customer_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'delivery_address_snapshot' => ['nullable', 'string'],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'order_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'daily_dish_portion_type' => ['nullable', 'string', 'in:plate,full,half'],
            'daily_dish_portion_quantity' => ['nullable', 'integer', 'min:1'],
            'menu_id' => ['nullable', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'subscription_main_menu_item_id' => ['nullable', 'integer'],
            'selected_items' => ['array'],
            'selected_items.*.menu_item_id' => ['nullable', 'integer'],
            'selected_items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'selected_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'selected_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'selected_items.*.sort_order' => ['nullable', 'integer'],
        ];
    }
}

