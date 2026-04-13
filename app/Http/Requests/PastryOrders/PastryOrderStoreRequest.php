<?php

namespace App\Http\Requests\PastryOrders;

use Illuminate\Foundation\Http\FormRequest;

class PastryOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'               => ['required', 'integer', 'exists:branches,id'],
            'status'                  => ['required', 'string', 'in:Draft,Confirmed,InProduction,Ready,Delivered,Cancelled'],
            'type'                    => ['required', 'string', 'in:Pickup,Delivery'],
            'customer_id'             => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name_snapshot'  => ['required', 'string', 'max:255'],
            'customer_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'delivery_address_snapshot' => ['nullable', 'string'],
            'scheduled_date'          => ['nullable', 'date'],
            'scheduled_time'          => ['nullable', 'date_format:H:i'],
            'notes'                   => ['nullable', 'string'],
            'image'                   => ['nullable', 'image', 'max:4096'],
            'order_discount_amount'   => ['nullable', 'numeric', 'min:0'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.menu_item_id'    => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity'        => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.sort_order'      => ['nullable', 'integer'],
        ];
    }
}
