<?php

namespace App\Services\CompanyFood;

use App\Models\CompanyFoodEmployeeList;
use App\Models\CompanyFoodOrder;
use App\Models\CompanyFoodProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyFoodOrderService
{
    public function create(CompanyFoodProject $project, array $data): CompanyFoodOrder
    {
        $orderDate = $this->resolveOrderDate($project, $data);
        $employeeList = $this->resolveEmployeeList($project, $data);
        $validOptionIds = $this->getValidActiveOptionIds($project, $orderDate, $employeeList->id);
        $categories = $employeeList->getCategorySlugs();
        $this->ensureListCategoriesHaveOptions($validOptionIds, $categories);

        $employeeNames = $employeeList->employees()->pluck('employee_name')->map(fn ($n) => (string) $n)->values()->all();
        $employeeRule = ! empty($employeeNames)
            ? ['required', 'string', 'max:255', Rule::in($employeeNames)]
            : ['required', 'string', 'max:255'];

        $rules = [
            'order_date' => ['required', 'date', 'after_or_equal:'.$project->start_date->format('Y-m-d'), 'before_or_equal:'.$project->end_date->format('Y-m-d')],
            'employee_list_id' => ['required', 'integer', Rule::exists('company_food_employee_lists', 'id')->where('project_id', $project->id)],
            'employee_name' => $employeeRule,
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];

        foreach ($categories as $cat) {
            if ($cat === 'appetizer') {
                $rules['appetizer_option_ids'] = ['required', 'array', 'size:2'];
                $rules['appetizer_option_ids.0'] = ['required', 'integer', Rule::in($validOptionIds['appetizer'] ?? [])];
                $rules['appetizer_option_ids.1'] = ['required', 'integer', Rule::in($validOptionIds['appetizer'] ?? [])];
            } else {
                $rules[$cat === 'soup' ? 'soup_option_id' : "{$cat}_option_id"] = ['required', 'integer', Rule::in($validOptionIds[$cat] ?? [])];
            }
        }

        $validated = validator($data, $rules)->validate();

        $orderData = [
            'project_id' => $project->id,
            'employee_list_id' => $employeeList->id,
            'order_date' => $orderDate,
            'employee_name' => $validated['employee_name'],
            'email' => $validated['email'] ?? null,
            'salad_option_id' => null,
            'appetizer_option_id_1' => null,
            'appetizer_option_id_2' => null,
            'main_option_id' => null,
            'sweet_option_id' => null,
            'location_option_id' => null,
            'soup_option_id' => null,
        ];

        foreach ($categories as $cat) {
            if ($cat === 'appetizer') {
                $orderData['appetizer_option_id_1'] = $validated['appetizer_option_ids'][0];
                $orderData['appetizer_option_id_2'] = $validated['appetizer_option_ids'][1];
            } elseif ($cat === 'soup') {
                $orderData['soup_option_id'] = $validated['soup_option_id'];
            } else {
                $orderData["{$cat}_option_id"] = $validated["{$cat}_option_id"];
            }
        }

        return DB::transaction(function () use ($orderData): CompanyFoodOrder {
            return CompanyFoodOrder::create($orderData);
        });
    }

    public function update(CompanyFoodOrder $order, array $data, string $editToken): CompanyFoodOrder
    {
        if ($order->edit_token !== $editToken) {
            throw ValidationException::withMessages(['edit_token' => ['Invalid edit token.']]);
        }

        $project = $order->project;
        $orderDate = $order->order_date;
        $employeeList = $order->employeeList;
        $validOptionIds = $this->getValidActiveOptionIds($project, $orderDate, $employeeList->id);
        $categories = $employeeList->getCategorySlugs();
        $this->ensureListCategoriesHaveOptions($validOptionIds, $categories);

        $employeeNames = $employeeList->employees()->pluck('employee_name')->map(fn ($n) => (string) $n)->values()->all();
        $employeeRule = ! empty($employeeNames)
            ? ['required', 'string', 'max:255', Rule::in($employeeNames)]
            : ['required', 'string', 'max:255'];

        $rules = [
            'employee_name' => $employeeRule,
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];

        foreach ($categories as $cat) {
            if ($cat === 'appetizer') {
                $rules['appetizer_option_ids'] = ['required', 'array', 'size:2'];
                $rules['appetizer_option_ids.0'] = ['required', 'integer', Rule::in($validOptionIds['appetizer'] ?? [])];
                $rules['appetizer_option_ids.1'] = ['required', 'integer', Rule::in($validOptionIds['appetizer'] ?? [])];
            } else {
                $rules[$cat === 'soup' ? 'soup_option_id' : "{$cat}_option_id"] = ['required', 'integer', Rule::in($validOptionIds[$cat] ?? [])];
            }
        }

        $validated = validator($data, $rules)->validate();

        $updateData = [
            'employee_name' => $validated['employee_name'],
            'email' => $validated['email'] ?? null,
            'salad_option_id' => null,
            'appetizer_option_id_1' => null,
            'appetizer_option_id_2' => null,
            'main_option_id' => null,
            'sweet_option_id' => null,
            'location_option_id' => null,
            'soup_option_id' => null,
        ];

        foreach ($categories as $cat) {
            if ($cat === 'appetizer') {
                $updateData['appetizer_option_id_1'] = $validated['appetizer_option_ids'][0];
                $updateData['appetizer_option_id_2'] = $validated['appetizer_option_ids'][1];
            } elseif ($cat === 'soup') {
                $updateData['soup_option_id'] = $validated['soup_option_id'];
            } else {
                $updateData["{$cat}_option_id"] = $validated["{$cat}_option_id"];
            }
        }

        $order->update($updateData);

        return $order->fresh(['saladOption', 'appetizerOption1', 'appetizerOption2', 'mainOption', 'sweetOption', 'locationOption', 'soupOption']);
    }

    private function resolveOrderDate(CompanyFoodProject $project, array $data): \Carbon\Carbon
    {
        $dateStr = $data['order_date'] ?? null;
        if (! $dateStr) {
            throw ValidationException::withMessages(['order_date' => ['The order date is required.']]);
        }

        $date = \Carbon\Carbon::parse($dateStr)->startOfDay();
        if ($date->lt($project->start_date) || $date->gt($project->end_date)) {
            throw ValidationException::withMessages(['order_date' => ['The order date must be within the project period.']]);
        }

        return $date;
    }

    private function resolveEmployeeList(CompanyFoodProject $project, array $data): CompanyFoodEmployeeList
    {
        $listId = $data['employee_list_id'] ?? null;
        if (! $listId) {
            throw ValidationException::withMessages(['employee_list_id' => ['The employee list is required.']]);
        }

        $list = CompanyFoodEmployeeList::where('project_id', $project->id)->find($listId);
        if (! $list) {
            throw ValidationException::withMessages(['employee_list_id' => ['Invalid employee list.']]);
        }

        return $list;
    }

    /**
     * @return array<string, array<int>>
     */
    private function getValidActiveOptionIds(CompanyFoodProject $project, \Carbon\Carbon $orderDate, int $employeeListId): array
    {
        $options = $project->activeOptions()
            ->whereDate('menu_date', $orderDate)
            ->get();

        $listSpecificCategories = ['main', 'soup'];
        $result = [];
        foreach (['salad', 'appetizer', 'main', 'sweet', 'location', 'soup'] as $cat) {
            $catOptions = $options->where('category', $cat);
            if (in_array($cat, $listSpecificCategories, true)) {
                $catOptions = $catOptions->where('employee_list_id', $employeeListId);
            }
            $result[$cat] = $catOptions->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return $result;
    }

    /**
     * @param  array<string, array<int>>  $validOptionIds
     * @param  array<string>  $categories
     */
    private function ensureListCategoriesHaveOptions(array $validOptionIds, array $categories): void
    {
        foreach ($categories as $category) {
            $ids = $validOptionIds[$category] ?? [];
            if (empty($ids)) {
                throw ValidationException::withMessages([
                    $category => ["No active options configured for {$category} on this date. Please contact the administrator."],
                ]);
            }
        }
    }
}
