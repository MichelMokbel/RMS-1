<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentCheckoutTargetItem;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\StorefrontCategory;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->actor = User::factory()->create(['status' => 'active']);
    $this->customer = Customer::factory()->create();
    $this->portalUser = User::factory()->create(['customer_id' => $this->customer->id, 'status' => 'active']);
    $clearing = LedgerAccount::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => 'skipcash',
        'name' => 'SkipCash',
        'method' => 'skipcash',
        'clearing_account_id' => $clearing->id,
        'is_active' => true,
    ]);
    PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
    $this->settings = StorefrontSetting::query()->create([
        'company_id' => $this->company->id,
        'portal_branch_id' => $this->branch->id,
        'normal_menu_enabled' => true,
        'menu_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
    $this->category = StorefrontCategory::query()->create([
        'company_id' => $this->company->id,
        'slug' => 'trays',
        'title' => 'Trays',
        'is_active' => true,
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
    $this->publish = function (string $name, int $order, bool $chef = false): array {
        $item = MenuItem::factory()->create([
            'name' => $name,
            'selling_price_per_unit' => '10.000',
            'unit' => MenuItem::UNIT_EACH,
            'is_active' => true,
        ]);
        DB::table('menu_item_branches')->updateOrInsert([
            'menu_item_id' => $item->id,
            'branch_id' => $this->branch->id,
        ], [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profile = StorefrontItemProfile::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'menu_item_id' => $item->id,
            'category_id' => $this->category->id,
            'customer_title' => $name,
            'direct_order_enabled' => true,
            'advance_days' => 1,
            'minimum_quantity' => '1.000',
            'quantity_increment' => '1.000',
            'is_chef_pick' => $chef,
            'display_order' => $order,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        return [$item, $profile];
    };
    $this->purchase = function (array $lines, string $finishedAt, array $overrides = []): array {
        $uuid = (string) Str::uuid();
        $attempt = PaymentCheckoutAttempt::query()->create([
            'reference' => $uuid,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'portal_user_id' => $this->portalUser->id,
            'payment_source_id' => $this->source->id,
            'client_uuid' => (string) Str::uuid(),
            'purpose' => $overrides['purpose'] ?? 'menu_order',
            'currency' => 'QAR',
            'gross_amount_cents' => 1000,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => 1000,
            'cart_fingerprint' => hash('sha256', 'cart-'.$uuid),
            'quote_fingerprint' => hash('sha256', 'quote-'.$uuid),
            'request_fingerprint' => hash('sha256', 'request-'.$uuid),
            'recovery_fingerprint' => hash('sha256', 'recovery-'.$uuid),
            'state' => $overrides['attempt_state'] ?? 'completed',
            'started_at' => $finishedAt,
            'expires_at' => CarbonImmutable::parse($finishedAt, 'UTC')->addMinutes(15),
            'completed_at' => $finishedAt,
            'cart_snapshot' => [],
            'customer_snapshot' => [],
            'pricing_snapshot' => [],
            'terms_snapshot' => [],
            'request_snapshot' => [],
            'source_account_snapshot' => [],
            'provider_request_uuid' => (string) Str::uuid(),
            'provider_create_outcome' => 'created',
        ]);
        PaymentProviderTransaction::query()->create([
            'attempt_id' => $attempt->id,
            'payment_source_id' => $this->source->id,
            'provider_payment_id' => 'provider-'.$uuid,
            'merchant_transaction_id' => $uuid,
            'amount_cents' => 1000,
            'currency' => 'QAR',
            'normalized_status' => 'paid',
            'verified_paid_at' => $finishedAt,
            'verified_amount_cents' => 1000,
            'verified_currency' => 'QAR',
            'verified_finished_at' => $finishedAt,
            'classification' => 'purchase',
        ]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => $overrides['order_status'] ?? 'Draft',
        ]);
        $invoice = ArInvoice::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => $overrides['invoice_status'] ?? 'paid',
            'invoice_number' => 'INV-'.substr(str_replace('-', '', $uuid), 0, 12),
            'issue_date' => CarbonImmutable::parse($finishedAt, 'UTC')->toDateString(),
            'due_date' => CarbonImmutable::parse($finishedAt, 'UTC')->toDateString(),
            'voided_at' => ($overrides['invoice_status'] ?? 'paid') === 'voided' ? $finishedAt : null,
        ]);
        $target = PaymentCheckoutTarget::query()->create([
            'attempt_id' => $attempt->id,
            'sequence' => 1,
            'target_type' => 'order',
            'service_date' => '2026-09-15',
            'expected_amount_cents' => 1000,
            'item_snapshot' => [],
            'hold_state' => 'activated',
            'held_at' => $finishedAt,
            'activated_at' => $finishedAt,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
        ]);
        foreach ($lines as $index => [$item, $quantity]) {
            PaymentCheckoutTargetItem::query()->create([
                'target_id' => $target->id,
                'sequence' => $index + 1,
                'menu_item_id' => $item->id,
                'title' => $item->name,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'unit_price_cents' => 1000,
                'line_total_cents' => 1000,
            ]);
        }

        return [$attempt, $target];
    };
});

it('ranks paid menu purchases over the previous seven complete Qatar dates and falls back to Chef picks', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:00:00 UTC');

    try {
        [$first, $firstProfile] = ($this->publish)('First Tray', 2);
        [$second, $secondProfile] = ($this->publish)('Second Tray', 1);
        [$chef] = ($this->publish)('Chef Tray', 3, true);
        ($this->purchase)([[$first, '2.000']], '2026-09-01 21:00:00');
        ($this->purchase)([[$first, '1.000'], [$second, '1.000']], '2026-09-05 12:00:00');
        ($this->purchase)([[$second, '1.000']], '2026-09-08 20:59:59');
        ($this->purchase)([[$second, '10.000']], '2026-09-06 12:00:00', ['invoice_status' => 'voided']);
        ($this->purchase)([[$second, '10.000']], '2026-09-08 21:00:00');

        $featured = $this->getJson('/api/public/storefront')->assertOk()->json('featured');
        expect($featured['source'])->toBe('popular')
            ->and(array_column($featured['items'], 'menu_item_id'))->toBe([$first->id, $second->id]);

        $firstProfile->update(['direct_order_enabled' => false]);
        $secondProfile->update(['direct_order_enabled' => false]);
        $fallback = $this->getJson('/api/public/storefront')->assertOk()->json('featured');
        expect($fallback['source'])->toBe('chef_picks')
            ->and($fallback['items'][0]['menu_item_id'])->toBe($chef->id);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
