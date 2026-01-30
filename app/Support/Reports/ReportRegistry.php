<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;

class ReportRegistry
{
    /**
     * @return Collection<int, array{key: string, label: string, route: string, filters: array, outputs: array}>
     */
    public static function all(): Collection
    {
        $reports = config('reports.reports', []);

        return collect($reports)->values();
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
