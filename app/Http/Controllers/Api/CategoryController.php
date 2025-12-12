<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with('parent')->alphabetical()->get();

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $category = Category::create($data);

        return response()->json($category, Response::HTTP_CREATED);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();

        if ($category->id && $data['parent_id'] && $category->wouldCreateCycle((int) $data['parent_id'])) {
            return response()->json([
                'message' => 'Parent selection creates a cycle.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($category->id === ($data['parent_id'] ?? null)) {
            return response()->json([
                'message' => 'A category cannot be its own parent.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category->update($data);

        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->isInUse()) {
            return response()->json([
                'message' => 'Category is in use and cannot be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
