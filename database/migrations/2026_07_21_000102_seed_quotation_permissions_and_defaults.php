<?php

use App\Services\Quotations\QuotationTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'quotations.access',
        'quotations.manage',
        'quotation-templates.manage',
        'quotations.convert',
    ];

    public function up(): void
    {
        $this->seedPermissions();
        $this->seedCompanyDefaults();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->whereIn('name', $this->permissions)
                ->delete();
        }
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('id', 'name');
        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'manager', 'cashier'])
            ->pluck('id', 'name');

        foreach (['admin', 'manager', 'cashier'] as $role) {
            foreach (['quotations.access', 'quotations.manage'] as $permission) {
                $this->assign($roleIds[$role] ?? null, $permissionIds[$permission] ?? null);
            }
        }

        foreach (['admin', 'manager'] as $role) {
            foreach (['quotation-templates.manage', 'quotations.convert'] as $permission) {
                $this->assign($roleIds[$role] ?? null, $permissionIds[$permission] ?? null);
            }
        }
    }

    private function assign(mixed $roleId, mixed $permissionId): void
    {
        if (! $roleId || ! $permissionId) {
            return;
        }

        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);
    }

    private function seedCompanyDefaults(): void
    {
        if (! Schema::hasTable('accounting_companies') || ! Schema::hasTable('document_templates')) {
            return;
        }

        foreach (DB::table('accounting_companies')->get(['id', 'name']) as $company) {
            DB::table('company_document_profiles')->insertOrIgnore([
                'company_id' => $company->id,
                'legal_name_en' => $company->name,
                'brand_color' => '#1F2937',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $templateId = DB::table('document_templates')
                ->where('company_id', $company->id)
                ->where('type', 'quotation')
                ->where('is_default', true)
                ->value('id');

            if (! $templateId) {
                $templateId = DB::table('document_templates')->insertGetId([
                    'company_id' => $company->id,
                    'type' => 'quotation',
                    'name' => 'Default Quotation',
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $versionId = DB::table('document_template_versions')
                ->where('document_template_id', $templateId)
                ->where('version', 1)
                ->value('id');

            if (! $versionId) {
                $versionId = DB::table('document_template_versions')->insertGetId([
                    'document_template_id' => $templateId,
                    'version' => 1,
                    'schema_version' => 1,
                    'page_settings' => json_encode($this->pageSettings()),
                    'styles' => json_encode($this->styles()),
                    'table_columns' => json_encode($this->tableColumns()),
                    'blocks' => json_encode($this->blocks()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('document_templates')->where('id', $templateId)->update([
                'current_version_id' => $versionId,
                'updated_at' => now(),
            ]);

            app(QuotationTemplateService::class)->ensureMenuProposal((int) $company->id);
        }
    }

    private function pageSettings(): array
    {
        return ['size' => 'A4', 'orientation' => 'portrait', 'margins_mm' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15]];
    }

    private function styles(): array
    {
        return ['font_family' => 'DejaVu Sans', 'font_size' => 10, 'heading_font_family' => 'DejaVu Sans', 'text_color' => '#111827', 'accent_color' => '#1F2937', 'default_direction' => 'auto'];
    }

    private function tableColumns(): array
    {
        return ['description' => true, 'quantity' => true, 'unit' => true, 'unit_price' => true, 'discount' => true, 'total' => true];
    }

    private function blocks(): array
    {
        return [
            ['id' => 'company-header', 'type' => 'company_header', 'direction' => 'auto', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch', 'show_logo' => true, 'logo_position' => 'start', 'alignment' => 'start']],
            ['id' => 'quotation-meta', 'type' => 'quotation_metadata', 'direction' => 'auto', 'settings' => ['column_span' => 6, 'new_row' => true, 'horizontal_alignment' => 'stretch']],
            ['id' => 'recipient', 'type' => 'recipient_details', 'direction' => 'auto', 'settings' => ['column_span' => 6, 'new_row' => false, 'horizontal_alignment' => 'stretch']],
            ['id' => 'items', 'type' => 'items_table', 'direction' => 'auto', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch']],
            ['id' => 'totals', 'type' => 'totals', 'direction' => 'auto', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'end']],
            ['id' => 'terms', 'type' => 'terms', 'direction' => 'auto', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch']],
            ['id' => 'signatures', 'type' => 'signature_lines', 'direction' => 'auto', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch', 'labels' => ['Prepared by', 'Accepted by']]],
        ];
    }
};
