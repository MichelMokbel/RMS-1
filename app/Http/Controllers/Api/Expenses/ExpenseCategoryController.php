<?php

namespace App\Http\Controllers\Api\Expenses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseCategoryStoreRequest;
use App\Http\Requests\Expenses\ExpenseCategoryUpdateRequest;
use App\Models\ExpenseCategory;
use App\Services\AP\ExpenseCategoryService;
use Illuminate\Http\Response;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    public function store(ExpenseCategoryStoreRequest $request, ExpenseCategoryService $service)
    {
        $cat = $service->save($request->validated(), (int) $request->user()->id);

        return response()->json($cat, Response::HTTP_CREATED);
    }

    public function update(ExpenseCategoryUpdateRequest $request, ExpenseCategory $category, ExpenseCategoryService $service)
    {
        return $service->save($request->validated(), (int) $request->user()->id, $category);
    }

    public function destroy(ExpenseCategory $category, ExpenseCategoryService $service)
    {
        $service->setActive($category, false, (int) request()->user()->id);

        return response()->noContent();
    }
}
