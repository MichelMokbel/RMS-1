<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: Arial, sans-serif; color: #171717;">
    <h1>{{ __('Daily AP Journal Entries') }}</h1>
    <p>{{ $snapshot['company'] }} · {{ $reportDate }}</p>
    <p><strong>{{ __('Document number') }}: {{ $documentNumber }}</strong></p>
    <p>{{ __('The attached report covers this accounting calendar day. It includes AP invoices, payments, allocations, cheque clearances, and reversal entries recorded when the report was generated.') }}</p>
    <p>{{ __('Revision :revision, generated :time.', ['revision' => $revision, 'time' => $generatedAt->format('Y-m-d H:i')]) }}</p>
    @if (count($snapshot['rows']) === 0)
        <p>{{ __('No AP journal entries were recorded for this day.') }}</p>
    @endif
    <p>{{ __('Later additions regenerate this daily report. Open the report to see the latest data.') }}</p>
    <p><a href="{{ route('reports.ap-journal', $snapshot['filters']) }}">{{ __('View current daily report') }}</a></p>
</body>
</html>
