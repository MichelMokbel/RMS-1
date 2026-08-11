@props(['attachment', 'canDelete' => false])

@php
    $extension = strtolower(pathinfo((string) $attachment->file_path, PATHINFO_EXTENSION));
    $previewUrl = route('payables.invoices.attachments.preview', [
        'invoice' => $attachment->invoice_id,
        'attachment' => $attachment->id,
    ]);
    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
@endphp

<div class="space-y-3 rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-700">
    <div class="flex items-center justify-between gap-4">
        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="truncate text-primary-700 hover:underline dark:text-primary-300">
            {{ $attachment->original_name }}
        </a>
        <div class="flex shrink-0 items-center gap-2">
            <flux:button :href="$previewUrl" target="_blank" variant="ghost" size="sm">{{ __('Open') }}</flux:button>
            @if($canDelete)
                <flux:button type="button" wire:click="deleteAttachment({{ $attachment->id }})" variant="ghost" size="sm">{{ __('Delete') }}</flux:button>
            @endif
        </div>
    </div>

    @if($isImage)
        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md bg-neutral-100 dark:bg-neutral-800">
            <img
                src="{{ $previewUrl }}"
                alt="{{ __('Preview of :name', ['name' => $attachment->original_name]) }}"
                loading="lazy"
                class="max-h-80 w-full object-contain"
            />
        </a>
    @elseif($extension === 'pdf')
        <iframe
            src="{{ $previewUrl }}"
            title="{{ __('Preview of :name', ['name' => $attachment->original_name]) }}"
            loading="lazy"
            class="h-80 w-full rounded-md border border-neutral-200 bg-white dark:border-neutral-700"
        ></iframe>
    @endif
</div>
