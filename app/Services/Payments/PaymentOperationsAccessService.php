<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Auth\Access\AuthorizationException;

class PaymentOperationsAccessService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
    ) {}

    public function assertCanView(User $actor, PaymentCheckoutAttempt $attempt): void
    {
        $this->assertPermission($actor, 'payments.support.view');
        $companyId = $this->accountingContext->defaultCompanyId();
        if (! $companyId || (int) $attempt->company_id !== $companyId) {
            throw new AuthorizationException(__('This checkout is outside your company.'));
        }
        if (! $actor->isAdmin() && ! in_array((int) $attempt->branch_id, $actor->allowedBranchIds(), true)) {
            throw new AuthorizationException(__('This checkout is outside your branch access.'));
        }
    }

    public function assertCanRecover(User $actor, PaymentCheckoutAttempt $attempt): void
    {
        $this->assertCanView($actor, $attempt);
        $this->assertPermission($actor, 'payments.support.recover');
    }

    public function assertCanResend(User $actor, PaymentCheckoutAttempt $attempt): void
    {
        $this->assertCanView($actor, $attempt);
        $this->assertPermission($actor, 'payments.support.resend');
    }

    public function assertCanManageSettings(User $actor, int $companyId): void
    {
        $this->assertPermission($actor, 'payments.settings.manage');
        if ($this->accountingContext->defaultCompanyId() !== $companyId) {
            throw new AuthorizationException(__('These payment settings are outside your company.'));
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->isActive() || $actor->isCustomerPortalUser()
            || ! $actor->isAdmin() && ! $actor->can($permission)) {
            throw new AuthorizationException(__('You are not authorized to manage payment operations.'));
        }
    }
}
