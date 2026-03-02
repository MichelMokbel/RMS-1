<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Food Employee Lists</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 20px; }
        h2 { margin: 0 0 4px; font-size: 16px; }
        .meta { font-size: 12px; color: #4b5563; margin: 0 0 12px; }
        .list-block { margin-top: 14px; page-break-inside: avoid; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f9fafb; font-weight: 700; }
        .empty { color: #6b7280; font-size: 12px; margin-top: 6px; }
        @include('reports.print-header-styles')
    </style>
</head>
<body>
    @include('reports.print-header', [
        'reportTitle' => 'Company Food Employee Lists',
        'generatedAt' => $generatedAt,
        'startAt' => $project->start_date,
        'endAt' => $project->end_date,
    ])

    <p class="meta">
        Project: {{ $project->name }} | Company: {{ $project->company_name }} | Date range:
        {{ $project->start_date->format('Y-m-d') }} to {{ $project->end_date->format('Y-m-d') }}
    </p>

    @forelse ($employeeLists as $list)
        <div class="list-block">
            <h2>{{ $list->name }}</h2>
            <p class="meta">
                Categories: {{ $list->listCategories->pluck('category')->map(fn ($c) => ucfirst($c))->implode(', ') }}
                | Employees: {{ $list->employees->count() }}
            </p>
            <table>
                <thead>
                    <tr>
                        <th style="width: 70px;">#</th>
                        <th>Employee Name</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($list->employees as $index => $employee)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $employee->employee_name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2">No employees in this list.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @empty
        <p class="empty">No employee lists found for this project.</p>
    @endforelse

    @include('reports.print-footer')
</body>
</html>
