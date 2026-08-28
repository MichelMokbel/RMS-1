<section id="category-review" aria-labelledby="category-review-heading" tabindex="-1" class="scroll-mt-6 rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="category-review-heading" class="text-lg font-semibold">{{ __('Category Definitions') }}</h2>
            @if($editable)
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Choose an active existing category or enter a new name. Saving applies the category to affected expenses across all dates, including invoices hidden by filters.') }}</p>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('New categories are created only when you confirm and commit. Unused workbook category definitions must also be reviewed.') }}</p>
            @else
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Category decisions are retained here as part of the import audit record.') }}</p>
            @endif
        </div>
        <p class="text-sm text-neutral-600 dark:text-neutral-300" role="status">
            {{ __(':create to create · :matched matched · :review need review', [
                'create' => $categoryProposals->where('status', 'proposed')->count(),
                'matched' => $categoryProposals->whereIn('status', ['matched', 'mapped', 'created'])->count(),
                'review' => $categoryProposals->whereIn('status', ['inactive', 'ambiguous', 'mapped_inactive'])->count(),
            ]) }}
        </p>
    </div>
    @if($categoryProposals->isEmpty())
        <p class="mt-3 text-sm text-neutral-500">{{ __('No category definitions were supplied.') }}</p>
    @else
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @foreach($categoryProposals as $proposal)
                <div wire:key="category-proposal-{{ $proposal->id }}" @class([
                    'min-w-0 rounded-lg border p-4',
                    'border-amber-300 dark:border-amber-800' => in_array($proposal->status, ['inactive', 'ambiguous', 'mapped_inactive'], true),
                    'border-neutral-200 dark:border-neutral-700' => ! in_array($proposal->status, ['inactive', 'ambiguous', 'mapped_inactive'], true),
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="category-title-{{ $proposal->id }}" class="text-sm font-semibold">
                            @if(filled($proposal->source_code)){{ $proposal->source_code }} · @endif{{ $proposal->source_name }}
                        </h3>
                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ match($proposal->status) {
                            'proposed' => __('Will create'), 'matched' => __('Existing'),
                            'mapped' => __('Selected'), 'created' => __('Created'),
                            'inactive', 'mapped_inactive' => __('Inactive — review'), 'ambiguous' => __('Ambiguous — review'),
                            default => Str::headline($proposal->status),
                        } }}</span>
                    </div>
                    @if(in_array($proposal->status, ['inactive', 'mapped_inactive'], true))
                        <p id="category-help-{{ $proposal->id }}" class="mt-2 text-sm text-amber-800 dark:text-amber-200">{{ __('This category is inactive or unavailable. Choose an active category or a different new name.') }}</p>
                    @elseif($proposal->status === 'ambiguous')
                        <p id="category-help-{{ $proposal->id }}" class="mt-2 text-sm text-amber-800 dark:text-amber-200">{{ __('More than one category has this name. Choose the correct category by its ID, or enter a different new name.') }}</p>
                    @elseif($proposal->category)
                        <p id="category-help-{{ $proposal->id }}" class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Uses :category', ['category' => $proposal->category->id.' | '.$proposal->category->name]) }}</p>
                    @else
                        <p id="category-help-{{ $proposal->id }}" class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('This new category will be created during commit.') }}</p>
                    @endif
                    @if($editable)
                        <form wire:submit="saveCategory({{ $proposal->id }})" aria-labelledby="category-title-{{ $proposal->id }}" aria-describedby="category-help-{{ $proposal->id }}" class="mt-3 space-y-3">
                            <div>
                                <label for="category-mode-{{ $proposal->id }}" class="mb-1 block text-sm font-medium">{{ __('Category action') }}</label>
                                <select id="category-mode-{{ $proposal->id }}" wire:model.live="categoryForms.{{ $proposal->id }}.mode" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                    <option value="existing">{{ __('Use existing category') }}</option>
                                    <option value="new">{{ __('Create a new category') }}</option>
                                </select>
                                <flux:error name="categoryForms.{{ $proposal->id }}.mode" />
                            </div>
                            @if(($categoryForms[$proposal->id]['mode'] ?? 'existing') === 'new')
                                <flux:input wire:model="categoryForms.{{ $proposal->id }}.name" :label="__('New category name')" maxlength="100" />
                            @else
                                <div>
                                    <label for="category-target-{{ $proposal->id }}" class="mb-1 block text-sm font-medium">{{ __('Existing category') }}</label>
                                    <select id="category-target-{{ $proposal->id }}" wire:model="categoryForms.{{ $proposal->id }}.category_id" aria-describedby="category-error-{{ $proposal->id }}" aria-invalid="{{ $errors->has('categoryForms.'.$proposal->id.'.category_id') ? 'true' : 'false' }}" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                        <option value="">{{ __('Choose an active category') }}</option>
                                        @foreach($allCategories as $category)
                                            <option value="{{ $category->id }}">{{ $category->id }} | {{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    <div id="category-error-{{ $proposal->id }}"><flux:error name="categoryForms.{{ $proposal->id }}.category_id" /></div>
                                    @if($allCategories->isEmpty())<p class="mt-1 text-sm text-neutral-500">{{ __('No active categories are available. Choose Create a new category above.') }}</p>@endif
                                </div>
                            @endif
                            <div class="flex justify-end">
                                <flux:button type="submit" size="sm" variant="primary" wire:loading.attr="disabled" wire:target="saveCategory({{ $proposal->id }})">{{ __('Save Category') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
