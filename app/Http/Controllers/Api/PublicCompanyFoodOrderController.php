<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyFoodOrder;
use App\Models\CompanyFoodProject;
use App\Services\CompanyFood\CompanyFoodOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicCompanyFoodOrderController extends Controller
{
    public function __construct(
        private CompanyFoodOrderService $orderService
    ) {}

    public function store(Request $request, string $projectSlug): JsonResponse
    {
        $project = $this->resolveProject($projectSlug);

        $payload = $request->all();
        $payload['appetizer_option_ids'] = $payload['appetizer_option_ids'] ?? [
            $payload['appetizer_option_id_1'] ?? null,
            $payload['appetizer_option_id_2'] ?? null,
        ];

        $order = $this->orderService->create($project, $payload);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'edit_token' => $order->edit_token,
        ], 201);
    }

    public function show(Request $request, string $projectSlug, int $id): JsonResponse
    {
        $project = $this->resolveProject($projectSlug);
        $editToken = $request->query('edit_token') ?? $request->header('X-Edit-Token');

        if (! $editToken) {
            return response()->json([
                'success' => false,
                'message' => 'edit_token is required.',
            ], 422);
        }

        $order = CompanyFoodOrder::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->where('edit_token', $editToken)
            ->with(['employeeList', 'saladOption', 'appetizerOption1', 'appetizerOption2', 'mainOption', 'sweetOption', 'locationOption', 'soupOption'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->formatOrder($order),
        ]);
    }

    public function update(Request $request, string $projectSlug, int $id): JsonResponse
    {
        $project = $this->resolveProject($projectSlug);
        $editToken = $request->input('edit_token') ?? $request->header('X-Edit-Token');

        if (! $editToken) {
            return response()->json([
                'success' => false,
                'message' => 'edit_token is required.',
            ], 422);
        }

        $order = CompanyFoodOrder::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            $payload = $request->all();
            $payload['appetizer_option_ids'] = $payload['appetizer_option_ids'] ?? [
                $payload['appetizer_option_id_1'] ?? null,
                $payload['appetizer_option_id_2'] ?? null,
            ];
            $order = $this->orderService->update($order, $payload, $editToken);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOrder($order),
        ]);
    }

    private function resolveProject(string $slug): CompanyFoodProject
    {
        return CompanyFoodProject::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function formatOrder(CompanyFoodOrder $order): array
    {
        return [
            'id' => $order->id,
            'employee_list_id' => $order->employee_list_id,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'employee_name' => $order->employee_name,
            'email' => $order->email,
            'salad_option_id' => $order->salad_option_id,
            'salad_option_name' => $order->saladOption?->name,
            'appetizer_option_ids' => [$order->appetizer_option_id_1, $order->appetizer_option_id_2],
            'appetizer_option_names' => [
                $order->appetizerOption1?->name,
                $order->appetizerOption2?->name,
            ],
            'main_option_id' => $order->main_option_id,
            'main_option_name' => $order->mainOption?->name,
            'sweet_option_id' => $order->sweet_option_id,
            'sweet_option_name' => $order->sweetOption?->name,
            'location_option_id' => $order->location_option_id,
            'location_option_name' => $order->locationOption?->name,
            'soup_option_id' => $order->soup_option_id,
            'soup_option_name' => $order->soupOption?->name,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }
}
