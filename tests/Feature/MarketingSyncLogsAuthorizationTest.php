<?php

use App\Models\MarketingPlatformAccount;
use App\Models\MarketingSyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
    Role::findOrCreate('cashier', 'web');
});

it('redirects guests from marketing sync logs', function () {
    $this->get(route('marketing.sync-logs.index'))->assertRedirect(route('login'));
});

it('forbids users without marketing access from marketing sync logs', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('cashier');

    $this->actingAs($user)->get(route('marketing.sync-logs.index'))->assertForbidden();
});

it('allows managers to view recent marketing sync logs', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'acct_123',
        'account_name' => 'Meta Main Account',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    MarketingSyncLog::query()->create([
        'platform_account_id' => $account->id,
        'sync_type' => 'campaigns',
        'status' => 'completed',
        'started_at' => now()->subMinutes(3),
        'completed_at' => now()->subMinutes(2),
        'records_synced' => 18,
        'context' => ['cursor' => 'abc123', 'batch' => 2],
    ]);

    $this->actingAs($user)
        ->get(route('marketing.sync-logs.index'))
        ->assertOk()
        ->assertSee('Meta Main Account')
        ->assertSee('Campaigns')
        ->assertSee('Completed')
        ->assertSee('18')
        ->assertSee('Context');
});
