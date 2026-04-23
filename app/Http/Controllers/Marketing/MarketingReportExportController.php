<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingCampaignQueryService;
use App\Support\Reports\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingReportExportController extends Controller
{
    public function csv(Request $request, MarketingCampaignQueryService $campaigns): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'in:meta,google'],
            'status' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $rows = $campaigns->getCampaignPerformanceRows(
            search: $filters['search'] ?? null,
            platform: $filters['platform'] ?? null,
            status: $filters['status'] ?? null,
            startDate: $filters['date_from'] ?? null,
            endDate: $filters['date_to'] ?? null,
            sort: 'name',
            direction: 'asc',
        );

        $headers = [
            __('Campaign'),
            __('Platform'),
            __('Account'),
            __('Status'),
            __('Objective'),
            __('Daily Budget'),
            __('MTD Spend'),
            __('Impressions'),
            __('Clicks'),
            __('Conversions'),
            __('Last Synced'),
        ];

        $csvRows = $rows->map(function ($row) {
            return [
                $row->name ?? '',
                $row->platformAccount->platform ?? '',
                $row->platformAccount->account_name ?? '',
                $row->status ?? '',
                $row->objective ?? '',
                $row->daily_budget_micro === null
                    ? ''
                    : number_format((float) ($row->daily_budget_micro / 1_000_000), 2, '.', ''),
                number_format((float) (($row->mtd_spend_micro ?? 0) / 1_000_000), 2, '.', ''),
                (string) ((int) ($row->mtd_impressions ?? 0)),
                (string) ((int) ($row->mtd_clicks ?? 0)),
                (string) ((int) ($row->mtd_conversions ?? 0)),
                filled($row->last_synced_at) ? Carbon::parse($row->last_synced_at)->toDateTimeString() : '',
            ];
        });

        return CsvExport::stream($headers, $csvRows, 'marketing-campaigns.csv');
    }
}
