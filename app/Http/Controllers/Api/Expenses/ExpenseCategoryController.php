<?php

namespace App\Http\Controllers\Api\Expenses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseCategoryStoreRequest;
use App\Http\Requests\Expenses\ExpenseCategoryUpdateRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    public function store(ExpenseCategoryStoreRequest $request)
    {
        $cat = ExpenseCategory::create($request->validated());
        return response()->json($cat, Response::HTTP_CREATED);
    }

    public function update(ExpenseCategoryUpdateRequest $request, ExpenseCategory $category)
    {
        $category->update($request->validated());
        return $category;
    }

    public function destroy(ExpenseCategory $category)
    {
        if ($category->isInUse()) {
            throw ValidationException::withMessages(['category' => __('Category in use and cannot be deleted.')]);
        }
        $category->delete();
        return response()->noContent();
    }
}
