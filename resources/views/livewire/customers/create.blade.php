<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $customer_code = null;
    public string $name = '';
    public string $customer_type = Customer::TYPE_RETAIL;
    public ?string $contact_name = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $billing_address = null;
    public ?string $delivery_address = null;
    public ?string $country = null;
    public ?int $default_payment_method_id = null;
    public float $credit_limit = 0;
    public int $credit_terms_days = 0;
    public ?string $credit_status = null;
    public bool $is_active = true;
    public ?string $notes = null;

    public function create(): void
    {
        $data = $this->validate($this->rules());

        if ($data['customer_type'] === Customer::TYPE_RETAIL) {
            $data['credit_limit'] = 0;
            $data['credit_terms_days'] = 0;
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'created_by')) {
            $data['created_by'] = auth()->id();
        }

        Customer::create($data);

        session()->flash('status', __('Customer created.'));
        $this->redirectRoute('customers.index', navigate: true);
    }

    private function rules(): array
    {
        return [
            'customer_code' => array_filter([
                'nullable', 'string', 'max:50',
                config('customers.enforce_unique_customer_code') ? 'unique:customers,customer_code' : null,
            ]),
            'name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', 'in:retail,corporate,subscription'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => array_filter([
                'nullable', 'string', 'max:50',
                config('customers.enforce_unique_phone') ? 'unique:customers,phone' : null,
            ]),
            'email' => array_filter([
                'nullable', 'email', 'max:255',
                config('customers.enforce_unique_email') ? 'unique:customers,email' : null,
            ]),
            'billing_address' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:100'],
            'default_payment_method_id' => ['nullable', 'integer', 'min:1'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'credit_terms_days' => ['required', 'integer', 'min:0'],
            'credit_status' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function paymentMethods(): array
    {
        if (! Schema::hasTable('payment_methods')) {
            return [];
        }

        return \DB::table('payment_methods')->select('id', 'name')->orderBy('name')->get()->toArray();
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Create Customer') }}
        </h1>
        <flux:button :href="route('customers.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="create" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="customer_code" :label="__('Customer Code')" maxlength="50" />
            <flux:input wire:model="name" :label="__('Name')" required maxlength="255" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Customer Type') }}</label>
                <select
                    wire:model="customer_type"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="retail">{{ __('Retail') }}</option>
                    <option value="corporate">{{ __('Corporate') }}</option>
                    <option value="subscription">{{ __('Subscription') }}</option>
                </select>
            </div>
            <flux:input wire:model="contact_name" :label="__('Contact Name')" maxlength="255" />
            <flux:input wire:model="phone" :label="__('Phone')" maxlength="50" />
            <flux:input wire:model="email" :label="__('Email')" type="email" maxlength="255" />
            <flux:input wire:model="country" :label="__('Country')" maxlength="100" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Default Payment Method') }}</label>
                @if(count($this->paymentMethods()) > 0)
                    <select
                        wire:model="default_payment_method_id"
                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                    >
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->paymentMethods() as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model="default_payment_method_id" type="number" min="1" :label="__('Payment Method ID (optional)')" />
                    <p class="text-xs text-neutral-500 mt-1">{{ __('No payment_methods table detected; enter ID manually if needed.') }}</p>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:textarea wire:model="billing_address" :label="__('Billing Address')" rows="3" />
            <flux:textarea wire:model="delivery_address" :label="__('Delivery Address')" rows="3" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="credit_limit" type="number" step="0.001" min="0" :label="__('Credit Limit')" />
            <flux:input wire:model="credit_terms_days" type="number" min="0" :label="__('Credit Terms Days')" />
            <flux:input wire:model="credit_status" :label="__('Credit Status')" maxlength="100" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="flex items-center gap-3">
                <flux:checkbox wire:model="is_active" :label="__('Active')" />
            </div>
            <flux:input wire:model="notes" :label="__('Notes')" />
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('customers.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Create') }}
            </flux:button>
        </div>
    </form>
</div>
