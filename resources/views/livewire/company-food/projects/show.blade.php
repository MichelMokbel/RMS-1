<?php

use App\Models\CompanyFoodEmployee;
use App\Models\CompanyFoodEmployeeList;
use App\Models\CompanyFoodListCategory;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodOrder;
use App\Models\CompanyFoodProject;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public CompanyFoodProject $project;
    public string $tab = 'orders';
    public ?int $filterLocationId = null;
    public ?int $filterListId = null;
    public ?string $filterOrderDate = null;

    // Options management
    public string $newOptionCategory = 'salad';
    public string $newOptionDate = '';
    public string $newOptionName = '';
    public ?int $editingOptionId = null;
    public string $editingOptionName = '';

    // Lists management
    public string $newListName = '';
    public ?int $editingListId = null;
    public string $editingListName = '';
    public array $editingListCategories = [];

    // Employees management (per list)
    public ?int $selectedListId = null;
    public string $newEmployeeName = '';
    public ?int $editingEmployeeId = null;
    public string $editingEmployeeName = '';
    public string $importText = '';
    public ?int $newOptionListId = null;

    // Menu drawer (day grid + slide-over)
    public bool $showMenuDrawer = false;
    public ?string $drawerMenuDate = null;
    public array $drawerNewOption = []; // category => name for quick add
    public ?int $drawerNewOptionListId = null; // for main/soup: which list to add to

    public function with(): array
    {
        $this->project->load([
            'options' => fn ($q) => $q->orderBy('menu_date')->orderBy('category')->orderBy('sort_order'),
            'employeeLists' => fn ($q) => $q->with(['listCategories', 'employees'])->orderBy('sort_order'),
        ]);

        $ordersQuery = CompanyFoodOrder::query()
            ->where('project_id', $this->project->id)
            ->with(['employeeList', 'saladOption', 'appetizerOption1', 'appetizerOption2', 'mainOption', 'sweetOption', 'locationOption', 'soupOption'])
            ->orderBy('employee_name');

        if ($this->filterLocationId) {
            $ordersQuery->where('location_option_id', $this->filterLocationId);
        }
        if ($this->filterListId) {
            $ordersQuery->where('employee_list_id', $this->filterListId);
        }
        if ($this->filterOrderDate) {
            $ordersQuery->whereDate('order_date', $this->filterOrderDate);
        }

        $orders = $ordersQuery->orderBy('order_date')->get();

        // Kitchen prep: grouped by order_date, then by list, then by location
        $kitchenPrep = $orders->groupBy(fn ($o) => $o->order_date?->format('Y-m-d'))->map(function ($dateOrders) {
            return $dateOrders->groupBy('employee_list_id')->map(function ($listOrders, $listId) {
                $list = $this->project->employeeLists->firstWhere('id', (int) $listId);
                $listName = $list?->name ?? __('Unknown');
                $categories = $list ? $list->getCategorySlugs() : ['salad', 'appetizer', 'main', 'sweet', 'location', 'soup'];

                $byLocation = $listOrders->groupBy('location_option_id');
                $sections = [];
                foreach ($byLocation as $locId => $locOrders) {
                    $locationName = $locOrders->first()?->locationOption?->name ?? ($locId ? __('Unknown') : __('No location'));
                    $counts = [
                        'salad' => $locOrders->groupBy('salad_option_id')->map->count(),
                        'appetizer' => $locOrders->flatMap(fn ($o) => array_filter([$o->appetizer_option_id_1, $o->appetizer_option_id_2]))
                            ->groupBy(fn ($id) => $id)->map->count(),
                        'main' => $locOrders->groupBy('main_option_id')->map->count(),
                        'sweet' => $locOrders->groupBy('sweet_option_id')->map->count(),
                        'soup' => $locOrders->groupBy('soup_option_id')->map->count(),
                    ];
                    $sections[] = ['name' => $locationName, 'counts' => $counts];
                }

                return ['listName' => $listName, 'sections' => $sections, 'categories' => $categories];
            })->all();
        });

        $orderDates = $orders->pluck('order_date')->unique()->filter()->map(fn ($d) => $d->format('Y-m-d'))->values()->all();

        // Options grouped by menu_date for the grid
        $optionsByDate = $this->project->options->groupBy(fn ($o) => $o->menu_date?->format('Y-m-d'));

        return [
            'orders' => $orders,
            'kitchenPrep' => $kitchenPrep,
            'locationOptions' => $this->project->options->where('category', 'location')->values(),
            'employeeLists' => $this->project->employeeLists,
            'orderDates' => $orderDates,
            'optionsByDate' => $optionsByDate,
        ];
    }

    public function openMenuDrawer(string $date): void
    {
        $this->drawerMenuDate = $date;
        $this->drawerNewOption = array_fill_keys(\App\Models\CompanyFoodOption::CATEGORIES, '');
        $this->drawerNewOptionListId = $this->project->employeeLists->first()?->id;
        $this->showMenuDrawer = true;
    }

    public function closeMenuDrawer(): void
    {
        $this->showMenuDrawer = false;
        $this->drawerMenuDate = null;
        $this->drawerNewOption = [];
        $this->drawerNewOptionListId = null;
        $this->cancelEditOption();
    }

    public function addDrawerOption(string $category): void
    {
        if (! $this->drawerMenuDate) {
            return;
        }

        $name = trim($this->drawerNewOption[$category] ?? '');
        if ($name === '') {
            session()->flash('error', __('Please enter a name.'));
            return;
        }

        $listSpecificCategories = ['main', 'soup'];
        $employeeListId = in_array($category, $listSpecificCategories, true)
            ? $this->drawerNewOptionListId
            : $this->project->employeeLists->first()?->id;

        if (! $employeeListId) {
            session()->flash('error', __('Please select a list for this option.'));
            return;
        }

        if (in_array($category, $listSpecificCategories, true) && ! $this->project->employeeLists->contains('id', $employeeListId)) {
            session()->flash('error', __('Invalid list selected.'));
            return;
        }

        $maxSort = $this->project->options()
            ->where('category', $category)
            ->where('employee_list_id', $employeeListId)
            ->whereDate('menu_date', $this->drawerMenuDate)
            ->max('sort_order') ?? 0;

        CompanyFoodOption::create([
            'project_id' => $this->project->id,
            'employee_list_id' => $employeeListId,
            'menu_date' => $this->drawerMenuDate,
            'category' => $category,
            'name' => $name,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        $this->drawerNewOption[$category] = '';
        session()->flash('status', __('Option added.'));
    }

    public function addOption(): void
    {
        $listSpecificCategories = ['main', 'soup'];
        $rules = [
            'newOptionCategory' => ['required', 'in:salad,appetizer,main,sweet,location,soup'],
            'newOptionDate' => ['required', 'date', 'after_or_equal:'.$this->project->start_date->format('Y-m-d'), 'before_or_equal:'.$this->project->end_date->format('Y-m-d')],
            'newOptionName' => ['required', 'string', 'max:255'],
        ];
        if (in_array($this->newOptionCategory, $listSpecificCategories, true)) {
            $rules['newOptionListId'] = ['required', 'integer', 'exists:company_food_employee_lists,id', 'in:'.$this->project->employeeLists->pluck('id')->implode(',')];
        }
        $this->validate($rules);

        $employeeListId = in_array($this->newOptionCategory, $listSpecificCategories, true)
            ? $this->newOptionListId
            : $this->project->employeeLists->first()?->id;

        if (! $employeeListId) {
            session()->flash('error', __('No list available.'));
            return;
        }

        $maxSort = $this->project->options()
            ->where('category', $this->newOptionCategory)
            ->where('employee_list_id', $employeeListId)
            ->whereDate('menu_date', $this->newOptionDate)
            ->max('sort_order') ?? 0;

        CompanyFoodOption::create([
            'project_id' => $this->project->id,
            'employee_list_id' => $employeeListId,
            'menu_date' => $this->newOptionDate,
            'category' => $this->newOptionCategory,
            'name' => trim($this->newOptionName),
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        $this->newOptionName = '';
        $this->newOptionDate = '';
        $this->newOptionListId = null;
        session()->flash('status', __('Option added.'));
    }

    public function toggleOptionActive(int $optionId): void
    {
        $option = CompanyFoodOption::where('project_id', $this->project->id)->findOrFail($optionId);
        $option->is_active = ! $option->is_active;
        $option->save();
        session()->flash('status', __('Option updated.'));
    }

    public function startEditOption(int $optionId): void
    {
        $option = CompanyFoodOption::where('project_id', $this->project->id)->findOrFail($optionId);
        $this->editingOptionId = $optionId;
        $this->editingOptionName = $option->name;
    }

    public function saveOption(): void
    {
        if (! $this->editingOptionId) {
            return;
        }

        $this->validate(['editingOptionName' => ['required', 'string', 'max:255']]);

        $option = CompanyFoodOption::where('project_id', $this->project->id)->findOrFail($this->editingOptionId);
        $option->name = trim($this->editingOptionName);
        $option->save();

        $this->editingOptionId = null;
        $this->editingOptionName = '';
        session()->flash('status', __('Option updated.'));
    }

    public function cancelEditOption(): void
    {
        $this->editingOptionId = null;
        $this->editingOptionName = '';
    }

    public function deleteOption(int $optionId): void
    {
        $option = CompanyFoodOption::where('project_id', $this->project->id)->findOrFail($optionId);
        $inUse = CompanyFoodOrder::query()
            ->where('project_id', $this->project->id)
            ->where(function ($q) use ($optionId) {
                $q->where('salad_option_id', $optionId)
                    ->orWhere('appetizer_option_id_1', $optionId)
                    ->orWhere('appetizer_option_id_2', $optionId)
                    ->orWhere('main_option_id', $optionId)
                    ->orWhere('sweet_option_id', $optionId)
                    ->orWhere('location_option_id', $optionId)
                    ->orWhere('soup_option_id', $optionId);
            })
            ->exists();
        if ($inUse) {
            session()->flash('error', __('Cannot delete: orders reference this option.'));
            return;
        }
        $option->delete();
        session()->flash('status', __('Option deleted.'));
    }

    public function addList(): void
    {
        $this->validate(['newListName' => ['required', 'string', 'max:255']]);

        $maxSort = $this->project->employeeLists()->max('sort_order') ?? 0;

        $list = CompanyFoodEmployeeList::create([
            'project_id' => $this->project->id,
            'name' => trim($this->newListName),
            'sort_order' => $maxSort + 1,
        ]);

        $this->newListName = '';
        $this->selectedListId = $list->id;
        session()->flash('status', __('List added. Add categories and employees.'));
    }

    public function startEditList(int $listId): void
    {
        $list = CompanyFoodEmployeeList::where('project_id', $this->project->id)->with('listCategories')->findOrFail($listId);
        $this->editingListId = $listId;
        $this->editingListName = $list->name;
        $this->editingListCategories = $list->listCategories->pluck('category')->values()->all();
    }

    public function saveList(): void
    {
        if (! $this->editingListId) {
            return;
        }

        $this->validate([
            'editingListName' => ['required', 'string', 'max:255'],
            'editingListCategories' => ['required', 'array', 'min:1'],
            'editingListCategories.*' => ['in:salad,appetizer,main,sweet,location,soup'],
        ]);

        $list = CompanyFoodEmployeeList::where('project_id', $this->project->id)->findOrFail($this->editingListId);
        $list->name = trim($this->editingListName);
        $list->save();

        $list->listCategories()->delete();
        foreach ($this->editingListCategories as $i => $cat) {
            CompanyFoodListCategory::create([
                'employee_list_id' => $list->id,
                'category' => $cat,
                'sort_order' => $i,
            ]);
        }

        $this->editingListId = null;
        $this->editingListName = '';
        $this->editingListCategories = [];
        session()->flash('status', __('List updated.'));
    }

    public function cancelEditList(): void
    {
        $this->editingListId = null;
        $this->editingListName = '';
        $this->editingListCategories = [];
    }

    public function deleteList(int $listId): void
    {
        $list = CompanyFoodEmployeeList::where('project_id', $this->project->id)->findOrFail($listId);
        if ($list->orders()->exists()) {
            session()->flash('error', __('Cannot delete: orders exist for this list.'));
            return;
        }
        $list->delete();
        if ($this->selectedListId === $listId) {
            $this->selectedListId = null;
        }
        session()->flash('status', __('List deleted.'));
    }

    public function addEmployee(): void
    {
        $this->validate([
            'selectedListId' => ['required', 'exists:company_food_employee_lists,id'],
            'newEmployeeName' => ['required', 'string', 'max:255'],
        ]);

        $list = CompanyFoodEmployeeList::where('project_id', $this->project->id)->findOrFail($this->selectedListId);
        $name = trim($this->newEmployeeName);
        $exists = $list->employees()->where('employee_name', $name)->exists();
        if ($exists) {
            session()->flash('error', __('Employee already exists in this list.'));
            return;
        }

        $maxSort = $list->employees()->max('sort_order') ?? 0;

        CompanyFoodEmployee::create([
            'project_id' => $this->project->id,
            'employee_list_id' => $list->id,
            'employee_name' => $name,
            'sort_order' => $maxSort + 1,
        ]);

        $this->newEmployeeName = '';
        session()->flash('status', __('Employee added.'));
    }

    public function startEditEmployee(int $employeeId): void
    {
        $emp = CompanyFoodEmployee::where('project_id', $this->project->id)->findOrFail($employeeId);
        $this->editingEmployeeId = $employeeId;
        $this->editingEmployeeName = $emp->employee_name;
    }

    public function saveEmployee(): void
    {
        if (! $this->editingEmployeeId) {
            return;
        }

        $this->validate(['editingEmployeeName' => ['required', 'string', 'max:255']]);

        $emp = CompanyFoodEmployee::where('project_id', $this->project->id)->findOrFail($this->editingEmployeeId);
        $name = trim($this->editingEmployeeName);
        $exists = $emp->employeeList->employees()->where('employee_name', $name)->where('id', '!=', $this->editingEmployeeId)->exists();
        if ($exists) {
            session()->flash('error', __('Employee name already exists in this list.'));
            return;
        }

        $emp->employee_name = $name;
        $emp->save();

        $this->editingEmployeeId = null;
        $this->editingEmployeeName = '';
        session()->flash('status', __('Employee updated.'));
    }

    public function cancelEditEmployee(): void
    {
        $this->editingEmployeeId = null;
        $this->editingEmployeeName = '';
    }

    public function deleteEmployee(int $employeeId): void
    {
        $emp = CompanyFoodEmployee::where('project_id', $this->project->id)->findOrFail($employeeId);
        $inUse = CompanyFoodOrder::query()
            ->where('employee_list_id', $emp->employee_list_id)
            ->where('employee_name', $emp->employee_name)
            ->exists();
        if ($inUse) {
            session()->flash('error', __('Cannot delete: orders reference this employee.'));
            return;
        }
        $emp->delete();
        session()->flash('status', __('Employee deleted.'));
    }

    public function importEmployees(): void
    {
        $this->validate(['selectedListId' => ['required', 'exists:company_food_employee_lists,id']]);

        $list = CompanyFoodEmployeeList::where('project_id', $this->project->id)->findOrFail($this->selectedListId);
        $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $this->importText)));
        $added = 0;
        $skipped = 0;
        $existing = $list->employees()->pluck('employee_name')->map(fn ($n) => strtolower((string) $n))->all();

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (in_array(strtolower($line), $existing, true)) {
                $skipped++;
                continue;
            }
            $maxSort = $list->employees()->max('sort_order') ?? 0;
            CompanyFoodEmployee::create([
                'project_id' => $this->project->id,
                'employee_list_id' => $list->id,
                'employee_name' => $line,
                'sort_order' => $maxSort + 1 + $added,
            ]);
            $existing[] = strtolower($line);
            $added++;
        }

        if ($added > 0 || $skipped > 0) {
            session()->flash('status', __('Added :added employee(s). Skipped :skipped duplicate(s).', ['added' => $added, 'skipped' => $skipped]));
        } else {
            session()->flash('status', __('No new employees to add.'));
        }

        $this->importText = '';
    }

}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Company Food Project') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $project->name }}</h1>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $project->company_name }} · {{ $project->start_date->format('M j, Y') }} – {{ $project->end_date->format('M j, Y') }}</p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                {{ __('API URL') }}: {{ url('/api/public/company-food/' . $project->slug . '/options') }}
            </p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('company-food.projects.edit', $project)" wire:navigate variant="ghost">{{ __('Edit') }}</flux:button>
            <flux:button :href="route('company-food.projects.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    <div class="space-y-4">
        <flux:radio.group wire:model.live="tab" variant="segmented">
            <flux:radio value="orders" icon="clipboard-document-list">{{ __('Orders') }}</flux:radio>
            <flux:radio value="kitchen" icon="rectangle-stack">{{ __('Kitchen Prep') }}</flux:radio>
            <flux:radio value="menu" icon="calendar-days">{{ __('Menu') }}</flux:radio>
            <flux:radio value="lists" icon="users">{{ __('Lists') }}</flux:radio>
            <flux:radio value="options" icon="cog-6-tooth">{{ __('Options') }}</flux:radio>
        </flux:radio.group>

        @if($tab === 'orders')
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 flex-wrap">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Filter by date') }}</label>
                        <input type="date" wire:model.live="filterOrderDate" min="{{ $project->start_date->format('Y-m-d') }}" max="{{ $project->end_date->format('Y-m-d') }}" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Filter by list') }}</label>
                        <select wire:model.live="filterListId" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('All') }}</option>
                            @foreach($employeeLists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }}</option>
                            @endforeach
                        </select>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Filter by location') }}</label>
                        <select wire:model.live="filterLocationId" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('All') }}</option>
                            @foreach($locationOptions as $opt)
                                <option value="{{ $opt->id }}">{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:button :href="route('company-food.projects.export-csv', $project)" variant="ghost" size="sm">{{ __('Export CSV') }}</flux:button>
                </div>
            </div>
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('List') }}</flux:table.column>
                        <flux:table.column>{{ __('Employee') }}</flux:table.column>
                        <flux:table.column>{{ __('Salad') }}</flux:table.column>
                        <flux:table.column>{{ __('Appetizer 1') }}</flux:table.column>
                        <flux:table.column>{{ __('Appetizer 2') }}</flux:table.column>
                        <flux:table.column>{{ __('Main') }}</flux:table.column>
                        <flux:table.column>{{ __('Sweet') }}</flux:table.column>
                        <flux:table.column>{{ __('Soup') }}</flux:table.column>
                        <flux:table.column>{{ __('Location') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse($orders as $order)
                            <flux:table.row>
                                <flux:table.cell>{{ $order->order_date?->format('M j, Y') ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->employeeList?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->employee_name }}</flux:table.cell>
                                <flux:table.cell>{{ $order->saladOption?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->appetizerOption1?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->appetizerOption2?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->mainOption?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->sweetOption?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->soupOption?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $order->locationOption?->name ?? '—' }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="10">{{ __('No orders yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        @if($tab === 'menu')
            @php
                $menuYear = $project->start_date->year;
                $menuStart = \Carbon\Carbon::create($menuYear, 2, 18);
                $menuEnd = \Carbon\Carbon::create($menuYear, 3, 19);
                $menuDays = [];
                for ($d = $menuStart->copy(); $d->lte($menuEnd); $d->addDay()) {
                    $menuDays[] = $d->format('Y-m-d');
                }
            @endphp
            <div class="space-y-4">
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Click a day to add menu options (salad, appetizer, main, sweet, soup, location) for that date.') }}</p>
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('18 Feb – 19 Mar') }} {{ $menuYear }} ({{ count($menuDays) }} {{ __('days') }})</p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                    @foreach($menuDays as $dateStr)
                        @php
                            $dateOpts = $optionsByDate->get($dateStr) ?? collect();
                            $count = $dateOpts->count();
                        @endphp
                        <div class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2 cursor-pointer hover:border-primary-400 dark:hover:border-primary-500 transition-colors" wire:click="openMenuDrawer('{{ $dateStr }}')">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ \Carbon\Carbon::parse($dateStr)->format('D M j') }}</p>
                                @if($count > 0)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                        {{ $count }} {{ __('options') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                        {{ __('Empty') }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Click to edit') }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($tab === 'lists')
            <div class="space-y-6">
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{{ __('Add List') }}</h3>
                    <form wire:submit="addList" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <flux:input wire:model="newListName" :label="__('List name')" placeholder="{{ __('e.g. List 1') }}" />
                        </div>
                        <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
                    </form>
                </div>

                @if($employeeLists->isNotEmpty())
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{{ __('Add / Import Employees') }}</h3>
                        <div class="flex flex-wrap items-end gap-3 mb-3">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Add to list') }}</label>
                                <select wire:model.live="selectedListId" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select list') }}</option>
                                    @foreach($employeeLists as $l)
                                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($selectedListId)
                                <form wire:submit="addEmployee" class="flex gap-2 items-end">
                                    <flux:input wire:model="newEmployeeName" placeholder="{{ __('Employee name') }}" class="min-w-[150px]" />
                                    <flux:button type="submit" size="sm" variant="primary">{{ __('Add') }}</flux:button>
                                </form>
                            @endif
                        </div>
                        @if($selectedListId)
                            <form wire:submit="importEmployees" class="flex gap-2 items-end">
                                <flux:textarea wire:model="importText" placeholder="{{ __('Paste names, one per line') }}" rows="3" class="flex-1 font-mono text-sm min-w-[200px]" />
                                <flux:button type="submit" size="sm" variant="ghost">{{ __('Import') }}</flux:button>
                            </form>
                        @endif
                    </div>
                @endif

                @foreach($employeeLists as $list)
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @if($editingListId === $list->id)
                            <div class="space-y-3 mb-4">
                                <flux:input wire:model="editingListName" :label="__('List name')" />
                                <div>
                                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-2">{{ __('Categories') }}</label>
                                    <div class="flex flex-wrap gap-3">
                                        @foreach(\App\Models\CompanyFoodOption::CATEGORIES as $c)
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model="editingListCategories" value="{{ $c }}" class="rounded border-neutral-300">
                                                <span>{{ ucfirst($c) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <flux:button wire:click="saveList" variant="primary">{{ __('Save') }}</flux:button>
                                    <flux:button wire:click="cancelEditList" variant="ghost">{{ __('Cancel') }}</flux:button>
                                </div>
                            </div>
                        @else
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {{ $list->name }}
                                    <span class="text-sm font-normal text-neutral-500">({{ $list->employees->count() }} {{ __('employees') }})</span>
                                </h3>
                                <div class="flex gap-2">
                                    <flux:button wire:click="startEditList({{ $list->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                    <flux:button wire:click="deleteList({{ $list->id }})" wire:confirm="{{ __('Delete this list?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                </div>
                            </div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-300 mb-3">
                                {{ __('Categories') }}: {{ implode(', ', array_map('ucfirst', $list->getCategorySlugs())) }}
                            </p>
                        @endif

                        <div class="border-t border-neutral-200 dark:border-neutral-700 pt-4 mt-4">
                            <h4 class="text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-2">{{ __('Employees') }}</h4>
                            <ul class="space-y-2">
                                @foreach($list->employees as $emp)
                                    <li class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                        @if($editingEmployeeId === $emp->id)
                                            <div class="flex-1 flex gap-2">
                                                <flux:input wire:model="editingEmployeeName" class="flex-1" wire:keydown.enter="saveEmployee" />
                                                <flux:button wire:click="saveEmployee" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                <flux:button wire:click="cancelEditEmployee" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        @else
                                            <span>{{ $emp->employee_name }}</span>
                                            <div class="flex items-center gap-2">
                                                <flux:button wire:click="startEditEmployee({{ $emp->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                                <flux:button wire:click="deleteEmployee({{ $emp->id }})" wire:confirm="{{ __('Delete this employee?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                                @if($list->employees->isEmpty())
                                    <li class="text-neutral-500 py-2">{{ __('No employees yet.') }}</li>
                                @endif
                            </ul>
                        </div>
                    </div>
                @endforeach
                @if($employeeLists->isEmpty())
                    <p class="text-neutral-600 dark:text-neutral-400">{{ __('No lists yet. Add a list to configure employees and categories.') }}</p>
                @endif
            </div>
        @endif

        @if($tab === 'kitchen')
            <div class="space-y-6">
                @foreach($kitchenPrep as $dateStr => $listsByDate)
                    @foreach($listsByDate as $data)
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{{ \Carbon\Carbon::parse($dateStr)->format('l, M j, Y') }} – {{ $data['listName'] }}</h3>
                        @foreach($data['sections'] as $section)
                            <div class="mb-4">
                                <h4 class="text-sm font-medium text-neutral-600 dark:text-neutral-300 mb-2">{{ $section['name'] }}</h4>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                    @foreach(['salad' => __('Salads'), 'appetizer' => __('Appetizers'), 'main' => __('Mains'), 'sweet' => __('Sweets'), 'soup' => __('Soups')] as $cat => $label)
                                        @if(in_array($cat, $data['categories'], true))
                                            <div>
                                                <h5 class="text-sm font-medium text-neutral-600 dark:text-neutral-300 mb-2">{{ $label }}</h5>
                                                <ul class="space-y-1 text-sm">
                                                    @php
                                                        $counts = $section['counts'][$cat] ?? collect();
                                                        $counts = $counts->filter(fn ($_, $id) => $id);
                                                        $optionIds = $counts->keys();
                                                        $optionNames = $project->options->whereIn('id', $optionIds)->keyBy('id');
                                                    @endphp
                                                    @foreach($counts as $optId => $cnt)
                                                        <li>{{ $optionNames->get($optId)?->name ?? __('Option #:id', ['id' => $optId]) }}: <strong>{{ $cnt }}</strong></li>
                                                    @endforeach
                                                    @if($counts->isEmpty())
                                                        <li class="text-neutral-500">{{ __('None') }}</li>
                                                    @endif
                                                </ul>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @endforeach
                @endforeach
                @if($kitchenPrep->isEmpty())
                    <p class="text-neutral-600 dark:text-neutral-400">{{ __('No orders yet. Kitchen prep will appear here.') }}</p>
                @endif
            </div>
        @endif

        @if($tab === 'options')
            <div class="space-y-6">
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{{ __('Add Option') }}</h3>
                    <form wire:submit="addOption" class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Date') }}</label>
                            <input type="date" wire:model="newOptionDate" min="{{ $project->start_date->format('Y-m-d') }}" max="{{ $project->end_date->format('Y-m-d') }}" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" required />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Category') }}</label>
                            <select wire:model="newOptionCategory" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach(\App\Models\CompanyFoodOption::CATEGORIES as $c)
                                    <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if(in_array($newOptionCategory, ['main', 'soup']))
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('List') }}</label>
                            <select wire:model="newOptionListId" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" required>
                                <option value="">{{ __('Select list...') }}</option>
                                @foreach($employeeLists as $list)
                                    <option value="{{ $list->id }}">{{ $list->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        <div class="flex-1 min-w-[200px]">
                            <flux:input wire:model="newOptionName" :label="__('Name')" placeholder="{{ __('e.g. Caesar Salad') }}" />
                        </div>
                        <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
                    </form>
                </div>

                @foreach($project->options->groupBy(fn ($o) => $o->menu_date?->format('Y-m-d')) as $dateStr => $dateOptions)
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{{ \Carbon\Carbon::parse($dateStr)->format('l, M j, Y') }}</h3>
                @foreach(\App\Models\CompanyFoodOption::CATEGORIES as $category)
                    @php
                        $opts = $dateOptions->where('category', $category);
                        $listSpecific = in_array($category, ['main', 'soup']);
                        $optsByList = $listSpecific ? $opts->groupBy('employee_list_id') : collect([null => $opts]);
                    @endphp
                    @if($opts->isNotEmpty())
                        <div class="mb-4">
                            <h4 class="text-sm font-medium text-neutral-600 dark:text-neutral-300 mb-2">{{ ucfirst($category) }}</h4>
                            @if($listSpecific)
                                @foreach($optsByList as $listId => $listOpts)
                                    @php $list = $listId ? $project->employeeLists->firstWhere('id', $listId) : null; @endphp
                                    @if($list)
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">{{ $list->name }}</p>
                                    @endif
                                    <ul class="space-y-2 mb-3 last:mb-0">
                                        @foreach($listOpts as $opt)
                                            <li class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                                @if($editingOptionId === $opt->id)
                                                    <div class="flex-1 flex gap-2">
                                                        <flux:input wire:model="editingOptionName" class="flex-1" wire:keydown.enter="saveOption" />
                                                        <flux:button wire:click="saveOption" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                        <flux:button wire:click="cancelEditOption" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                                    </div>
                                                @else
                                                    <span>{{ $opt->name }}</span>
                                                    <div class="flex items-center gap-2">
                                                        @if($opt->is_active)
                                                            <flux:badge color="green">{{ __('Active') }}</flux:badge>
                                                        @else
                                                            <flux:badge color="zinc">{{ __('Hidden') }}</flux:badge>
                                                        @endif
                                                        <flux:button wire:click="toggleOptionActive({{ $opt->id }})" size="sm" variant="ghost">{{ $opt->is_active ? __('Hide') : __('Show') }}</flux:button>
                                                        <flux:button wire:click="startEditOption({{ $opt->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                                        <flux:button wire:click="deleteOption({{ $opt->id }})" wire:confirm="{{ __('Delete this option?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endforeach
                            @else
                            <ul class="space-y-2">
                                @foreach($opts as $opt)
                                    <li class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                        @if($editingOptionId === $opt->id)
                                            <div class="flex-1 flex gap-2">
                                                <flux:input wire:model="editingOptionName" class="flex-1" wire:keydown.enter="saveOption" />
                                                <flux:button wire:click="saveOption" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                <flux:button wire:click="cancelEditOption" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        @else
                                            <span>{{ $opt->name }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($opt->is_active)
                                                    <flux:badge color="green">{{ __('Active') }}</flux:badge>
                                                @else
                                                    <flux:badge color="zinc">{{ __('Hidden') }}</flux:badge>
                                                @endif
                                                <flux:button wire:click="toggleOptionActive({{ $opt->id }})" size="sm" variant="ghost">{{ $opt->is_active ? __('Hide') : __('Show') }}</flux:button>
                                                <flux:button wire:click="startEditOption({{ $opt->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                                <flux:button wire:click="deleteOption({{ $opt->id }})" wire:confirm="{{ __('Delete this option?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @endif
                        </div>
                    @endif
                @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Menu drawer (right slide-over) --}}
    <style>
        .cf-menu-drawer { position: fixed; inset: 0; z-index: 99999; pointer-events: none; }
        .cf-menu-drawer[data-open="1"] { pointer-events: auto; }
        .cf-menu-drawer__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); opacity: 0; transition: opacity 200ms ease; }
        .cf-menu-drawer[data-open="1"] .cf-menu-drawer__backdrop { opacity: 1; }
        .cf-menu-drawer__panel { position: absolute; top: 0; right: 0; height: 100%; width: min(42rem, 100%); transform: translateX(100%); transition: transform 250ms ease; overflow-y: auto; background: #fff; box-shadow: -20px 0 60px rgba(0,0,0,.2); border-left: 1px solid rgba(0,0,0,.08); }
        .cf-menu-drawer[data-open="1"] .cf-menu-drawer__panel { transform: translateX(0); }
        .dark .cf-menu-drawer__panel { background: rgb(23 23 23); border-left-color: rgba(255,255,255,.12); }
    </style>

    <div class="cf-menu-drawer" data-open="{{ $showMenuDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" aria-hidden="{{ $showMenuDrawer ? 'false' : 'true' }}">
        <div class="cf-menu-drawer__backdrop" wire:click="closeMenuDrawer"></div>

        <div class="cf-menu-drawer__panel">
            @if($showMenuDrawer && $drawerMenuDate)
                <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/90">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ __('Company Food Menu') }}</p>
                            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ \Carbon\Carbon::parse($drawerMenuDate)->format('l, F j, Y') }}
                            </h2>
                        </div>
                        <flux:button size="sm" type="button" variant="ghost" wire:click="closeMenuDrawer">{{ __('Close') }}</flux:button>
                    </div>
                </div>

                <div class="p-4 space-y-6">
                    @if (session('status'))
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                            {{ session('error') }}
                        </div>
                    @endif

                    @foreach(\App\Models\CompanyFoodOption::CATEGORIES as $category)
                        @php
                            $listSpecific = in_array($category, ['main', 'soup']);
                            $opts = ($optionsByDate->get($drawerMenuDate) ?? collect())->where('category', $category)->sortBy('sort_order');
                            $optsByList = $listSpecific ? $opts->groupBy('employee_list_id') : collect([null => $opts]);
                        @endphp
                        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{{ ucfirst($category) }}</h3>
                            <div class="flex flex-wrap gap-2 mb-3">
                                @if($listSpecific)
                                    <select wire:model="drawerNewOptionListId" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        @foreach($employeeLists as $list)
                                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <flux:input wire:model="drawerNewOption.{{ $category }}" placeholder="{{ __('Add option...') }}" class="flex-1 min-w-[120px]" wire:keydown.enter="addDrawerOption('{{ $category }}')" />
                                <flux:button type="button" wire:click="addDrawerOption('{{ $category }}')" size="sm" variant="primary">{{ __('Add') }}</flux:button>
                            </div>
                            @if($listSpecific)
                                @foreach($optsByList as $listId => $listOpts)
                                    @php $list = $listId ? $employeeLists->firstWhere('id', $listId) : null; @endphp
                                    <div class="mb-3 last:mb-0">
                                        @if($list)
                                            <h4 class="text-sm font-medium text-neutral-600 dark:text-neutral-400 mb-2">{{ $list->name }}</h4>
                                        @endif
                                        <ul class="space-y-2">
                                            @foreach($listOpts as $opt)
                                                <li class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                                    @if($editingOptionId === $opt->id)
                                                        <div class="flex-1 flex gap-2">
                                                            <flux:input wire:model="editingOptionName" class="flex-1" wire:keydown.enter="saveOption" />
                                                            <flux:button wire:click="saveOption" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                            <flux:button wire:click="cancelEditOption" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                                        </div>
                                                    @else
                                                    <span>{{ $opt->name }}</span>
                                                    <div class="flex items-center gap-2">
                                                        @if($opt->is_active)
                                                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                                        @else
                                                            <flux:badge color="zinc" size="sm">{{ __('Hidden') }}</flux:badge>
                                                        @endif
                                                        <flux:button wire:click="toggleOptionActive({{ $opt->id }})" size="sm" variant="ghost">{{ $opt->is_active ? __('Hide') : __('Show') }}</flux:button>
                                                        <flux:button wire:click="startEditOption({{ $opt->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                                        <flux:button wire:click="deleteOption({{ $opt->id }})" wire:confirm="{{ __('Delete this option?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                                    </div>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                                @if($opts->isEmpty())
                                    <p class="text-neutral-500 py-2 text-sm">{{ __('No options yet. Add one above.') }}</p>
                                @endif
                            @else
                                <ul class="space-y-2">
                                    @foreach($opts as $opt)
                                        <li class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                            @if($editingOptionId === $opt->id)
                                                <div class="flex-1 flex gap-2">
                                                    <flux:input wire:model="editingOptionName" class="flex-1" wire:keydown.enter="saveOption" />
                                                    <flux:button wire:click="saveOption" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                    <flux:button wire:click="cancelEditOption" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                                </div>
                                            @else
                                            <span>{{ $opt->name }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($opt->is_active)
                                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                                @else
                                                    <flux:badge color="zinc" size="sm">{{ __('Hidden') }}</flux:badge>
                                                @endif
                                                <flux:button wire:click="toggleOptionActive({{ $opt->id }})" size="sm" variant="ghost">{{ $opt->is_active ? __('Hide') : __('Show') }}</flux:button>
                                                <flux:button wire:click="startEditOption({{ $opt->id }})" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                                                <flux:button wire:click="deleteOption({{ $opt->id }})" wire:confirm="{{ __('Delete this option?') }}" size="sm" variant="ghost" class="text-red-600">{{ __('Delete') }}</flux:button>
                                            </div>
                                            @endif
                                        </li>
                                    @endforeach
                                    @if($opts->isEmpty())
                                        <li class="text-neutral-500 py-2 text-sm">{{ __('No options yet. Add one above.') }}</li>
                                    @endif
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
