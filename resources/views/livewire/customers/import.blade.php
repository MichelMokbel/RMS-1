<?php

use App\Services\Customers\CustomerImportService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public $file;
    public string $mode = 'create';
    public array $previewRows = [];
    public array $previewHeaders = [];
    public array $result = [];

    public function updatedFile(): void
    {
        $this->reset(['previewRows', 'previewHeaders', 'result']);
        $this->validate(['file' => ['required', 'file', 'mimes:csv']]);

        $path = $this->file->store('imports');
        $service = app(CustomerImportService::class);
        $preview = $service->preview(storage_path('app/'.$path), 20);
        $this->previewRows = $preview['rows'];
        $this->previewHeaders = array_values($preview['headers']);
    }

    public function import(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv'],
            'mode' => ['required', 'in:create,upsert'],
        ]);

        $path = $this->file->store('imports');
        $service = app(CustomerImportService::class);
        $this->result = $service->import(storage_path('app/'.$path), $this->mode);

        session()->flash('status', __('Import completed.'));
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Import Customers (CSV)') }}
        </h1>
        <flux:button :href="route('customers.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit.prevent="import" class="space-y-4">
        <flux:input type="file" wire:model="file" accept=".csv" :label="__('CSV File')" />

        <div>
            <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Mode') }}</label>
            <select
                wire:model="mode"
                class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
            >
                <option value="create">{{ __('Create only (skip matches)') }}</option>
                <option value="upsert">{{ __('Upsert (update matches)') }}</option>
            </select>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="submit" variant="primary" :disabled="!$file">
                {{ __('Import') }}
            </flux:button>
        </div>
    </form>

    @if ($previewRows)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold mb-2 text-neutral-800 dark:text-neutral-100">{{ __('Preview (first 20 rows)') }}</h2>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            @foreach ($previewHeaders as $header)
                                <th class="px-2 py-1 text-left text-xs font-semibold uppercase text-neutral-600 dark:text-neutral-200">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previewRows as $row)
                            <tr class="border-t border-neutral-200 dark:border-neutral-700">
                                @foreach ($previewHeaders as $header)
                                    <td class="px-2 py-1 text-neutral-800 dark:text-neutral-100">{{ $row[$header] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($result)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ __('Import Summary') }}</h2>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                {{ __('Created') }}: {{ $result['created'] ?? 0 }},
                {{ __('Updated') }}: {{ $result['updated'] ?? 0 }},
                {{ __('Skipped') }}: {{ $result['skipped'] ?? 0 }},
                {{ __('Failed') }}: {{ $result['failed'] ?? 0 }}
            </p>
            @if (!empty($result['errors']))
                <div class="text-sm text-amber-700 dark:text-amber-200">
                    <strong>{{ __('Errors') }}:</strong>
                    <ul class="list-disc ml-5">
                        @foreach ($result['errors'] as $error)
                            <li>{{ implode('; ', $error['messages']) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
