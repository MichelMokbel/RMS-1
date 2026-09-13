<?php

namespace App\Services\Orders;

use App\Models\OrderLabelPrinterProfile;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class OrderLabelAgentInstallerService
{
    private const SUMATRA_URL = 'https://www.sumatrapdfreader.org/dl/rel/3.6.1/SumatraPDF-3.6.1-64.zip';

    private const SUMATRA_SHA256 = '98b33a518d42986856d225064b0cd2d3643ecf78cbf84ab873d26cc51877a544';

    public function __construct(
        private readonly AccountingAuditLogService $audit,
    ) {}

    /**
     * @return array{filename:string,contents:string}
     */
    public function build(OrderLabelPrinterProfile $profile, User $actor, string $baseUrl): array
    {
        $this->assertCanManage($actor);
        $baseUrl = $this->validateBaseUrl($baseUrl);

        return DB::transaction(function () use ($profile, $actor, $baseUrl): array {
            $locked = OrderLabelPrinterProfile::query()
                ->with('terminal')
                ->lockForUpdate()
                ->findOrFail($profile->id);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'installer' => __('Disable this printer before generating replacement setup credentials.'),
                ]);
            }

            $terminal = $locked->terminal;
            if (! $terminal instanceof PosTerminal || ! $terminal->active) {
                throw ValidationException::withMessages([
                    'installer' => __('This printer does not have an active print device. Save the profile first.'),
                ]);
            }

            if (! $terminal->device_id) {
                $terminal->forceFill(['device_id' => (string) Str::uuid()])->save();
            }

            $agent = $this->provisionAgentUser($terminal);
            $tokenName = 'pos:'.$terminal->device_id;
            $agent->tokens()->delete();
            $plainTextToken = $agent->createToken($tokenName, [
                'pos.print',
                'device:'.$terminal->device_id,
            ])->plainTextToken;

            $config = [
                'base_url' => $baseUrl,
                'token' => $plainTextToken,
                'device_id' => (string) $terminal->device_id,
                'queue_name' => (string) $locked->os_queue_name,
                'media_mode' => (string) $locked->media_mode,
                'width_tenths_mm' => (int) $locked->width_tenths_mm,
                'height_tenths_mm' => (int) ($locked->height_tenths_mm ?? 0),
                'min_height_tenths_mm' => (int) ($locked->min_height_tenths_mm ?? 0),
                'max_height_tenths_mm' => (int) ($locked->max_height_tenths_mm ?? 0),
                'resolution_dpi' => (int) $locked->resolution_dpi,
                'profile_id' => (int) $locked->id,
            ];

            $installer = $this->renderInstaller($config);

            $this->audit->log('order_label_printer.windows_setup_generated', (int) $actor->id, $locked, [
                'terminal_id' => (int) $terminal->id,
                'device_id_suffix' => substr((string) $terminal->device_id, -8),
                'queue_name' => (string) $locked->os_queue_name,
                'profile_revision' => (int) $locked->revision,
            ], (int) $locked->company_id);

            return [
                'filename' => 'Layla-Print-Agent-'.$this->safeFilename((string) $locked->code).'.ps1',
                'contents' => $installer,
            ];
        }, 3);
    }

    private function provisionAgentUser(PosTerminal $terminal): User
    {
        $username = '__label_agent_t'.$terminal->id;
        $expectedName = 'Label print agent '.$terminal->code;
        $agent = User::query()->where('username', $username)->lockForUpdate()->first();

        if ($agent && (string) $agent->name !== $expectedName) {
            throw ValidationException::withMessages([
                'installer' => __('The reserved print agent identity is already in use.'),
            ]);
        }

        if (! $agent) {
            $agent = User::query()->create([
                'username' => $username,
                'name' => $expectedName,
                'email' => null,
                'password' => Str::random(64),
                'status' => 'active',
                'pos_enabled' => true,
            ]);
        } else {
            $agent->forceFill([
                'status' => 'active',
                'pos_enabled' => true,
            ])->save();
        }

        $permission = Permission::findByName('pos.login', 'web');
        $agent->syncRoles([]);
        $agent->syncPermissions([$permission]);
        $agent->branches()->sync([(int) $terminal->branch_id]);

        return $agent->fresh();
    }

    /**
     * @param  array<string, int|string>  $config
     */
    private function renderInstaller(array $config): string
    {
        $templatePath = resource_path('scripts/order-label-agent/install.ps1');
        $agentPath = resource_path('scripts/order-label-agent/agent.ps1');
        $template = file_get_contents($templatePath);
        $agent = file_get_contents($agentPath);
        if ($template === false || $agent === false) {
            throw ValidationException::withMessages([
                'installer' => __('The Windows print agent package is unavailable on this deployment.'),
            ]);
        }

        $configJson = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return strtr($template, [
            '__AGENT_SCRIPT_BASE64__' => base64_encode($agent),
            '__CONFIG_BASE64__' => base64_encode($configJson),
            '__SUMATRA_URL_BASE64__' => base64_encode(self::SUMATRA_URL),
            '__SUMATRA_SHA256__' => self::SUMATRA_SHA256,
            '__PROFILE_ID__' => (string) $config['profile_id'],
        ]);
    }

    private function validateBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])) {
            throw ValidationException::withMessages([
                'installer' => __('The RMS URL is invalid.'),
            ]);
        }

        return $baseUrl;
    }

    private function safeFilename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?: 'Printer';

        return trim($value, '-');
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->hasRole('admin') && $actor->can('order-label-printers.manage'), 403);
    }
}
