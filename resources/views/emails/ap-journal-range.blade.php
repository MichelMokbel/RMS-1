<p>{{ __('The AP journal reports for :company from :from to :to are attached as one PDF.', ['company' => $company, 'from' => $dateFrom, 'to' => $dateTo]) }}</p>
<p>{{ trans_choice(':count daily report was generated.|:count daily reports were generated.', count($reports), ['count' => count($reports)]) }}</p>
<p>{{ __('Document numbers') }}: {{ $reports[0]['document_number'] }} {{ __('to') }} {{ $reports[count($reports) - 1]['document_number'] }}</p>
<p><a href="{{ route('reports.ap-journal', ['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}">{{ __('View the current AP journal range') }}</a></p>
