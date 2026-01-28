<?php

use App\Models\Supplier;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\Volt\With;
use Illuminate\Validation\Rule;

new #[Layout('components.layouts.app')] class extends Component {
    public Supplier $supplier;
    public string $name = '';
    public ?string $contact_person = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $address = null;
    public ?string $qid_cr = null;
    public string $status = 'active';

    public function mount(Supplier $supplier): void
    {
        $this->supplier = $supplier;
        $this->name = $supplier->name;
        $this->contact_person = $supplier->contact_person;
        $this->email = $supplier->email;
        $this->phone = $supplier->phone;
        $this->address = $supplier->address;
        $this->qid_cr = $supplier->qid_cr;
        $this->status = $supplier->status ?? 'active';
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());

        if (! $data['status']) {
            $data['status'] = 'active';
        }

        $this->supplier->update($data);

        session()->flash('status', __('Supplier updated.'));
        $this->redirectRoute('suppliers.index', navigate: true);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('suppliers', 'name')->ignore($this->supplier->id)],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\\-\\s\\.xX]+$/'],
            'address' => ['nullable', 'string'],
            'qid_cr' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}; ?>

<div class="w-full max-w-3xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Edit Supplier') }}
        </h1>
        <flux:button :href="route('suppliers.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" required maxlength="100" />
            <flux:input wire:model="contact_person" :label="__('Contact Person')" maxlength="100" />
            <flux:input wire:model="email" :label="__('Email')" type="email" maxlength="100" />
            <flux:input wire:model="phone" :label="__('Phone')" maxlength="20" />
            <flux:input wire:model="qid_cr" :label="__('QID/CR')" maxlength="100" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Status') }}</label>
                <select
                    wire:model="status"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>
        </div>

        <flux:textarea wire:model="address" :label="__('Address')" rows="3" />

        <div class="flex justify-end gap-3">
            <flux:button :href="route('suppliers.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>
</div>
