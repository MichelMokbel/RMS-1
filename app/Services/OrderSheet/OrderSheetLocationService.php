<?php

namespace App\Services\OrderSheet;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderSheetLocationService
{
    public function forCustomers(Collection $customers): Collection
    {
        $customerIds = $customers->modelKeys();
        if ($customerIds === []) {
            return collect();
        }

        $requestAddresses = DB::table('meal_plan_requests')
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('delivery_address')
            ->where('delivery_address', '!=', '')
            ->orderByDesc('id')
            ->get(['customer_id', 'delivery_address'])
            ->groupBy('customer_id');
        $portalAddresses = DB::table('users')
            ->whereIn('customer_id', $customerIds)
            ->pluck('portal_delivery_address', 'customer_id');

        return $customers->mapWithKeys(function (Customer $customer) use ($requestAddresses, $portalAddresses) {
            $address = collect([
                $requestAddresses->get($customer->id)?->first()?->delivery_address,
                $portalAddresses->get($customer->id),
                $customer->delivery_address,
            ])->first(fn ($value) => filled($value));

            return [$customer->id => $address ?? ''];
        });
    }

    public function forOrders(Collection $orders): Collection
    {
        $ids = $orders->modelKeys();
        $requests = DB::table('meal_plan_request_orders as links')
            ->join('meal_plan_requests as requests', 'requests.id', '=', 'links.meal_plan_request_id')
            ->whereIn('links.order_id', $ids)->orderByDesc('requests.id')
            ->get(['links.order_id', 'requests.delivery_address'])->groupBy('order_id');
        $subscriptions = DB::table('meal_subscription_orders as links')
            ->join('meal_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.subscription_id')
            ->whereIn('links.order_id', $ids)->orderByDesc('subscriptions.id')
            ->get(['links.order_id', 'subscriptions.address_snapshot'])->groupBy('order_id');
        $customers = Customer::whereIn('id', $orders->pluck('customer_id')->filter())->pluck('delivery_address', 'id');

        return $orders->mapWithKeys(function ($order) use ($requests, $subscriptions, $customers) {
            $address = collect([$order->delivery_address_snapshot])
                ->concat(($requests->get($order->id) ?? collect())->pluck('delivery_address'))
                ->concat(($subscriptions->get($order->id) ?? collect())->pluck('address_snapshot'))
                ->push($customers->get($order->customer_id))
                ->first(fn ($value) => filled($value));

            return [$order->id => $address ?? ''];
        });
    }
}
