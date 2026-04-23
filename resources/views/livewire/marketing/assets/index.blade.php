<?php

use App\Models\MarketingAsset;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $type = '';

    public string $status = '';

    protected $paginationTheme = 'tailwind';

    protected $queryString = [
        'search' => ['except' => ''],
        'type' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    #[On('marketing-assets-updated')]
    public function refreshAssets(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'assets' => MarketingAsset::query()
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->when($this->type, fn ($q) => $q->ofType($this->type))
                ->when($this->status, fn ($q) => $q->withStatus($this->status))
                ->with('uploadedBy')
                ->orderByDesc('created_at')
                ->paginate(24),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Asset Library') }}</h1>
        @if(auth()->user()->can('marketing.access'))
            <flux:button variant="primary" icon="arrow-up-tray" x-on:click="$dispatch('marketing-upload-open')">
                {{ __('Upload Asset') }}
            </flux:button>
        @endif
    </div>

    @if(auth()->user()->can('marketing.access'))
        <div
            x-data="marketingAssetUploader({
                presignUrl: '{{ route('marketing.assets.presign') }}',
                completeUrl: '{{ route('marketing.assets.complete') }}',
            })"
            x-on:marketing-upload-open.window="openPicker()"
            class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4 4 4M4 20h16" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Upload a marketing asset') }}</p>
                            <p class="text-xs text-zinc-500">{{ __('Choose a file, upload it directly to S3, then finalize the asset record.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <input type="file" x-ref="fileInput" class="hidden" x-on:change="handleSelection($event)" />
                    <flux:button type="button" variant="ghost" icon="folder-open" x-on:click="openPicker()">
                        {{ __('Choose File') }}
                    </flux:button>
                    <flux:button type="button" variant="primary" icon="arrow-up-tray" x-bind:disabled="!file || busy" x-on:click="upload()">
                        <span x-text="busy ? 'Uploading…' : '{{ __('Upload') }}'"></span>
                    </flux:button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-4">
                <div class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('File') }}</p>
                    <p class="mt-1 truncate text-sm text-zinc-900 dark:text-white" x-text="file ? file.name : '{{ __('No file selected') }}'"></p>
                </div>
                <div class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('Type') }}</p>
                    <p class="mt-1 text-sm text-zinc-900 capitalize dark:text-white" x-text="assetType || '—'"></p>
                </div>
                <div class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('Size') }}</p>
                    <p class="mt-1 text-sm text-zinc-900 dark:text-white" x-text="file ? formatSize(file.size) : '—'"></p>
                </div>
                <div class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-[11px] uppercase tracking-wide text-zinc-500">{{ __('Status') }}</p>
                    <p class="mt-1 text-sm text-zinc-900 dark:text-white" x-text="statusLabel"></p>
                </div>
            </div>

            <div class="mt-4" x-show="busy || statusMessage || errorMessage || progress > 0" x-cloak>
                <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                    <div class="h-2 rounded-full bg-blue-600 transition-all" x-bind:style="`width: ${progress}%`"></div>
                </div>
                <p class="mt-2 text-sm" x-bind:class="errorMessage ? 'text-rose-600' : 'text-zinc-600 dark:text-zinc-300'" x-text="errorMessage || statusMessage"></p>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search assets…') }}"
                    icon="magnifying-glass"
                />
            </div>
            <select
                wire:model.live="type"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
            >
                <option value="">{{ __('All Types') }}</option>
                <option value="image">{{ __('Image') }}</option>
                <option value="video">{{ __('Video') }}</option>
                <option value="copy">{{ __('Copy') }}</option>
                <option value="document">{{ __('Document') }}</option>
            </select>
            <select
                wire:model.live="status"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="pending_review">{{ __('Pending Review') }}</option>
                <option value="approved">{{ __('Approved') }}</option>
                <option value="rejected">{{ __('Rejected') }}</option>
                <option value="archived">{{ __('Archived') }}</option>
            </select>
        </div>
    </div>

    {{-- Grid --}}
    @if($assets->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white px-4 py-16 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('No assets yet') }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                @if($search || $type || $status)
                    {{ __('Try adjusting your filters.') }}
                @else
                    {{ __('Upload your first marketing asset to get started.') }}
                @endif
            </p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach($assets as $asset)
                <a
                    href="{{ route('marketing.assets.show', $asset) }}"
                    wire:navigate
                    class="group rounded-lg border border-zinc-200 bg-white p-3 hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-blue-500 transition-colors"
                >
                    <div class="aspect-square rounded-md bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center text-zinc-400 mb-2">
                        @if($asset->type === 'image')
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                            </svg>
                        @elseif($asset->type === 'video')
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                        @else
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                        @endif
                    </div>
                    <p class="text-xs font-medium text-zinc-900 dark:text-white truncate">{{ $asset->name }}</p>
                    <div class="mt-1 flex items-center gap-1">
                        <span class="text-xs text-zinc-500 capitalize">{{ $asset->type }}</span>
                        <span class="text-zinc-300">·</span>
                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium
                            @if($asset->status === 'approved') bg-emerald-100 text-emerald-700
                            @elseif($asset->status === 'pending_review') bg-amber-100 text-amber-700
                            @elseif($asset->status === 'rejected') bg-red-100 text-red-700
                            @else bg-zinc-100 text-zinc-600 @endif">
                            {{ str_replace('_', ' ', $asset->status) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
        <div>
            {{ $assets->links('pagination::tailwind') }}
        </div>
    @endif
</div>

@once
    <script>
        (function () {
            const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const inferType = (file) => {
                const mime = (file?.type || '').toLowerCase();
                const name = (file?.name || '').toLowerCase();

                if (mime.startsWith('image/')) return 'image';
                if (mime.startsWith('video/')) return 'video';
                if (
                    mime.startsWith('text/') ||
                    ['application/json', 'application/xml', 'application/javascript', 'application/x-javascript', 'text/csv', 'application/csv', 'text/markdown'].includes(mime) ||
                    /\.(txt|md|csv|json|xml|html?|js|mjs|ts|tsx|css|scss|less|yml|yaml|log)$/i.test(name)
                ) {
                    return 'copy';
                }

                return 'document';
            };

            const imageDimensions = (file) => new Promise((resolve) => {
                if (!file || !file.type.startsWith('image/')) {
                    resolve({ width: null, height: null });
                    return;
                }

                const objectUrl = URL.createObjectURL(file);
                const image = new Image();
                image.onload = () => {
                    URL.revokeObjectURL(objectUrl);
                    resolve({ width: image.naturalWidth || null, height: image.naturalHeight || null });
                };
                image.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    resolve({ width: null, height: null });
                };
                image.src = objectUrl;
            });

            const putObject = (url, file, onProgress) => new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', url, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable && typeof onProgress === 'function') {
                        onProgress(Math.round((event.loaded / event.total) * 100));
                    }
                };
                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                        return;
                    }

                    reject(new Error(`S3 upload failed (${xhr.status})`));
                };
                xhr.onerror = () => reject(new Error('S3 upload failed.'));
                xhr.send(file);
            });

            const postJson = async (url, payload) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Request failed.');
                }

                return data;
            };

            const register = () => {
                if (!window.Alpine || window.__marketingAssetUploaderRegistered) {
                    return;
                }

                window.__marketingAssetUploaderRegistered = true;
                window.Alpine.data('marketingAssetUploader', ({ presignUrl, completeUrl }) => ({
                    presignUrl,
                    completeUrl,
                    file: null,
                    assetType: '',
                    busy: false,
                    progress: 0,
                    statusLabel: '{{ __('Idle') }}',
                    statusMessage: '',
                    errorMessage: '',

                    openPicker() {
                        this.$refs.fileInput?.click();
                    },

                    handleSelection(event) {
                        const [file] = event.target.files || [];
                        this.file = file || null;
                        this.assetType = file ? inferType(file) : '';
                        this.progress = 0;
                        this.errorMessage = '';
                        this.statusMessage = file ? '{{ __('Ready to upload') }}' : '{{ __('Idle') }}';
                        this.statusLabel = file ? '{{ __('File selected') }}' : '{{ __('Idle') }}';
                    },

                    formatSize(bytes) {
                        if (!bytes && bytes !== 0) return '—';
                        if (bytes < 1024) return `${bytes} B`;
                        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                        if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
                        return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
                    },

                    async upload() {
                        if (!this.file || this.busy) {
                            return;
                        }

                        this.busy = true;
                        this.errorMessage = '';
                        this.statusMessage = '{{ __('Requesting upload URL...') }}';
                        this.statusLabel = '{{ __('Starting') }}';
                        this.progress = 5;

                        try {
                            const dimensions = await imageDimensions(this.file);
                            const presign = await postJson(this.presignUrl, {
                                name: this.file.name,
                                mime_type: this.file.type || 'application/octet-stream',
                            });

                            this.statusMessage = '{{ __('Uploading file to S3...') }}';
                            this.statusLabel = '{{ __('Uploading') }}';
                            this.progress = 10;

                            await putObject(presign.url, this.file, (pct) => {
                                this.progress = Math.max(10, Math.min(92, pct));
                                this.statusMessage = `{{ __('Uploading file to S3...') }} ${this.progress}%`;
                            });

                            this.statusMessage = '{{ __('Finalizing asset...') }}';
                            this.statusLabel = '{{ __('Completing') }}';
                            this.progress = 95;

                            await postJson(this.completeUrl, {
                                name: this.file.name,
                                type: this.assetType || inferType(this.file),
                                s3_key: presign.s3_key,
                                bucket: presign.bucket,
                                mime_type: this.file.type || 'application/octet-stream',
                                file_size: this.file.size,
                                width: dimensions.width,
                                height: dimensions.height,
                            });

                            this.statusLabel = '{{ __('Complete') }}';
                            this.statusMessage = '{{ __('Upload completed.') }}';
                            this.progress = 100;
                            this.file = null;
                            this.assetType = '';
                            if (this.$refs.fileInput) {
                                this.$refs.fileInput.value = '';
                            }

                            window.Livewire?.dispatch('marketing-assets-updated');
                        } catch (error) {
                            this.errorMessage = error?.message || '{{ __('Upload failed.') }}';
                            this.statusLabel = '{{ __('Failed') }}';
                            this.statusMessage = '';
                        } finally {
                            this.busy = false;
                        }
                    },
                }));
            };

            if (window.Alpine) {
                register();
            } else {
                document.addEventListener('alpine:init', register, { once: true });
            }
        })();
    </script>
@endonce
