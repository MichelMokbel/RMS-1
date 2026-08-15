<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = [
        'hr.access',
        'hr.employees.view',
        'hr.employees.manage',
        'hr.employees.export',
        'hr.documents.view',
        'hr.documents.manage',
        'hr.documents.download',
        'hr.documents.export',
        'hr.leave.view',
        'hr.leave.manage',
        'hr.leave.approve',
        'hr.payroll.view',
        'hr.payroll.prepare',
        'hr.payroll.approve',
        'hr.payroll.post',
        'hr.payroll.pay',
        'hr.payroll.reverse',
        'hr.payroll.export',
        'hr.reports.view',
        'hr.reports.export',
        'hr.settings.manage',
        'hr.imports.manage',
        'hr.audit.view',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        DB::table('roles')->insertOrIgnore([
            'name' => 'hr',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('id', 'name');

        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'hr', 'manager', 'accounting'])
            ->pluck('id', 'name');

        $assignments = [
            'admin' => $this->permissions,
            'hr' => [
                'hr.access',
                'hr.employees.view', 'hr.employees.manage', 'hr.employees.export',
                'hr.documents.view', 'hr.documents.manage', 'hr.documents.download', 'hr.documents.export',
                'hr.leave.view', 'hr.leave.manage', 'hr.leave.approve',
                'hr.payroll.view', 'hr.payroll.prepare', 'hr.payroll.approve', 'hr.payroll.export',
                'hr.reports.view', 'hr.reports.export',
                'hr.settings.manage', 'hr.imports.manage', 'hr.audit.view',
            ],
            'manager' => [
                'hr.access', 'hr.employees.view', 'hr.leave.view', 'hr.leave.approve',
            ],
            'accounting' => [
                'hr.access', 'hr.payroll.view', 'hr.payroll.post', 'hr.payroll.pay',
                'hr.payroll.reverse', 'hr.payroll.export', 'hr.reports.view', 'hr.reports.export',
            ],
        ];

        foreach ($assignments as $roleName => $names) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($names as $name) {
                $permissionId = $permissionIds[$name] ?? null;
                if ($permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }
    }

    /** Forward-only: deployment rollback must not silently revoke HR access. */
    public function down(): void
    {
        // No-op.
    }
};
