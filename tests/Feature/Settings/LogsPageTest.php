<?php

use App\Models\EmailLog;
use App\Models\OpsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('staff', 'web');
    Permission::findOrCreate('finance.access', 'web');
});

it('allows admins to view the settings logs page with ops and email data', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    OpsEvent::query()->create([
        'event_type' => 'customer_portal_order_submission_completed',
        'actor_user_id' => $user->id,
        'metadata_json' => ['audit_id' => 'abc123'],
        'created_at' => now(),
    ]);

    EmailLog::query()->create([
        'category' => 'daily_dish_order',
        'recipient_type' => 'admin',
        'mailable' => \App\Mail\DailyDishOrderAdminMail::class,
        'subject' => 'Test Subject',
        'mailer' => 'smtp',
        'status' => 'sent',
        'to_recipients' => ['ops@example.com'],
        'user_id' => $user->id,
        'context' => ['order_ids' => [1]],
        'sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('settings.logs'))
        ->assertOk()
        ->assertSee('Ops Events')
        ->assertSee('Email Logs')
        ->assertSee('customer_portal_order_submission_completed')
        ->assertSee('Test Subject');
});

it('forbids users without finance settings access from viewing the settings logs page', function () {
    $user = User::factory()->create();
    $user->assignRole('staff');

    $this->actingAs($user)
        ->get(route('settings.logs'))
        ->assertForbidden();
});
