<?php

use App\Http\Controllers\HR\HrDocumentController;
use App\Http\Controllers\HR\HrImportTemplateController;
use App\Http\Controllers\HR\HrPayslipController;
use App\Http\Controllers\HR\HrReportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'active', 'role_or_permission:admin|hr.access'])
    ->prefix('hr')
    ->name('hr.')
    ->group(function (): void {
        Volt::route('/', 'hr.dashboard')->name('dashboard');

        Route::middleware('role_or_permission:admin|hr.employees.view')->group(function (): void {
            Volt::route('employees', 'hr.employees.index')->name('employees.index');
        });
        Volt::route('employees/create', 'hr.employees.create')
            ->middleware('role_or_permission:admin|hr.employees.manage')
            ->name('employees.create');
        Volt::route('employees/{employee}/edit', 'hr.employees.edit')
            ->middleware('role_or_permission:admin|hr.employees.manage')
            ->name('employees.edit');
        Volt::route('employees/{employee}', 'hr.employees.show')
            ->middleware('role_or_permission:admin|hr.employees.view')
            ->name('employees.show');

        Route::middleware('role_or_permission:admin|hr.documents.download')->group(function (): void {
            Route::get('documents/{document}/preview', [HrDocumentController::class, 'preview'])->name('documents.preview');
            Route::get('documents/{document}/download', [HrDocumentController::class, 'download'])->name('documents.download');
        });

        Volt::route('leave', 'hr.leave.index')
            ->middleware('role_or_permission:admin|hr.leave.view')
            ->name('leave.index');
        Volt::route('payroll', 'hr.payroll.index')
            ->middleware('role_or_permission:admin|hr.payroll.view')
            ->name('payroll.index');
        Route::get('payroll/results/{result}/payslip', HrPayslipController::class)
            ->middleware('role_or_permission:admin|hr.payroll.view')
            ->name('payroll.payslip');
        Volt::route('imports', 'hr.imports.index')
            ->middleware('role_or_permission:admin|hr.imports.manage')
            ->name('imports.index');
        Volt::route('imports/{batch}', 'hr.imports.show')
            ->whereNumber('batch')
            ->middleware('role_or_permission:admin|hr.imports.manage')
            ->name('imports.show');
        Route::get('imports/template/{type}', HrImportTemplateController::class)
            ->whereIn('type', ['full_history', 'employees', 'assignments', 'documents', 'leave', 'payroll'])
            ->middleware('role_or_permission:admin|hr.imports.manage')
            ->name('imports.template');
        Volt::route('reports', 'hr.reports.index')
            ->middleware('role_or_permission:admin|hr.reports.view')
            ->name('reports.index');
        Volt::route('settings', 'hr.settings.index')
            ->middleware('role_or_permission:admin|hr.settings.manage')
            ->name('settings.index');
        Volt::route('audit', 'hr.audit.index')
            ->middleware('role_or_permission:admin|hr.audit.view')
            ->name('audit.index');

        Route::get('reports/{report}/print', [HrReportController::class, 'print'])
            ->whereIn('report', ['directory', 'leave-balances', 'payroll-register'])
            ->middleware('role_or_permission:admin|hr.reports.export|hr.payroll.export')
            ->name('reports.print');
        Route::get('reports/{report}/xlsx', [HrReportController::class, 'xlsx'])
            ->whereIn('report', ['directory', 'leave-balances', 'payroll-register'])
            ->middleware('role_or_permission:admin|hr.reports.export|hr.payroll.export')
            ->name('reports.xlsx');
    });
