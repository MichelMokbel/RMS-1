<?php

use App\Models\Customer;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $customer_id = null;
    public int $branch_id = 1;
    public string $status = 'active';
    public string $start_date;
    public ?string $end_date = null;
    public string $default_order_type = 'Delivery';
    public ?string $delivery_time = null;
    public ?string $address_snapshot = null;
    public ?string $phone_snapshot = null;
    public string $preferred_role = 'main';
    public bool $include_salad = true;
    public bool $include_dessert = true;
    public ?string $notes = null;
    public array $weekdays = [1,2,3,4,5];

    public function mount(): void
    {
        $this->start_date = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->get() : collect(),
        ];
    }

    public function save(MealSubscriptionService $service): void
    {
        $data = $this->validate($this->rules());
        $data['weekdays'] = $this->weekdays;

        $sub = $service->save($data, null, Illuminate\Support\Facades\Auth::id());

        session()->flash('status', __('Subscription created.'));
        $this->redirectRoute('subscriptions.show', $sub, navigate: true);
    }

    private function rules(): array
    {
        $branchRule = ['required', 'integer'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        return [
            'customer_id' => ['required', 'integer'],
            'branch_id' => $branchRule,
            'status' => ['required', 'in:active,paused,cancelled,expired'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'default_order_type' => ['required', 'in:Delivery,Takeaway'],
            'delivery_time' => ['nullable', 'date_format:H:i'],
            'address_snapshot' => ['nullable', 'string'],
            'phone_snapshot' => ['nullable', 'string', 'max:50'],
            'preferred_role' => ['required', 'in:main,diet,vegetarian'],
            'include_salad' => ['boolean'],
            'include_dessert' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'weekdays' => ['array', 'min:1'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
        ];
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Subscription') }}</h1>
        <flux:button :href="route('subscriptions.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <select wire:model="customer_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select customer') }}</option>
                    @foreach($customers as $cust)
                        <option value="{{ $cust->id }}">{{ $cust->name }}</option>
                    @endforeach
                </select>
            </div>
            <flux:input wire:model="branch_id" type="number" :label="__('Branch ID')" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="start_date" type="date" :label="__('Start Date')" />
            <flux:input wire:model="end_date" type="date" :label="__('End Date')" />
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="paused">{{ __('Paused') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                    <option value="expired">{{ __('Expired') }}</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Order Type') }}</label>
                <select wire:model="default_order_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Delivery">{{ __('Delivery') }}</option>
                    <option value="Takeaway">{{ __('Takeaway') }}</option>
                </select>
            </div>
            <flux:input wire:model="delivery_time" type="time" :label="__('Delivery Time')" />
            <flux:input wire:model="phone_snapshot" :label="__('Phone')" />
        </div>

        <flux:textarea wire:model="address_snapshot" :label="__('Address')" rows="2" />
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Preferred Role') }}</label>
                <select wire:model="preferred_role" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="main">{{ __('Main') }}</option>
                    <option value="diet">{{ __('Diet') }}</option>
                    <option value="vegetarian">{{ __('Vegetarian') }}</option>
                </select>
            </div>
            <div class="flex items-center gap-3 pt-6">
                <flux:checkbox wire:model="include_salad" :label="__('Include Salad')" />
                <flux:checkbox wire:model="include_dessert" :label="__('Include Dessert')" />
            </div>
        </div>

        <div>
            <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-2">{{ __('Weekdays') }}</p>
            <div class="flex flex-wrap gap-2">
                @for($d=1; $d<=7; $d++)
                    <label class="flex items-center gap-2 text-sm text-neutral-800 dark:text-neutral-100">
                        <input type="checkbox" wire:model="weekdays" value="{{ $d }}" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600">
                        {{ \Carbon\Carbon::create()->startOfWeek()->addDays($d-1)->format('D') }}
                    </label>
                @endfor
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button :href="route('subscriptions.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
    </div>
</div>
