<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;

class ReportRegistry
{
    /**
     * @return Collection<int, array{key: string, label: string, category?: string, route: string, filters: array, outputs: array}>
     */
    public static function all(): Collection
    {
        $reports = config('reports.reports', []);

        return collect($reports)->values();
    }

    /**
     * @return Collection<int, array{key: string, label: string}>
     */
    public static function categories(): Collection
    {
        $categories = config('reports.categories', []);

        return collect($categories)->values();
    }

    /**
     * @return Collection<int, array{key: string, label: string, category?: string, route: string, filters: array, outputs: array}>
     */
    public static function allInCategory(string $category): Collection
    {
        $reports = config('reports.reports', []);

        return collect($reports)
            ->filter(fn (array $report) => ($report['category'] ?? null) === $category)
            ->values();
    }

    /**
     * @return array{key: string, label: string, route: string, filters: array, outputs: array}|null
     */
    public static function find(string $key): ?array
    {
        $reports = config('reports.reports', []);

        return $reports[$key] ?? null;
    }
}
