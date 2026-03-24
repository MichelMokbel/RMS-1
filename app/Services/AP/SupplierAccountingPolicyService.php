<?php

namespace App\Services\AP;

use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

class SupplierAccountingPolicyService
{
    public function resolveHoldStatus(?Supplier $supplier): string
    {
        return strtolower((string) ($supplier?->hold_status ?: 'open'));
    }

    public function blocksDraft(?Supplier $supplier): bool
    {
        return $this->resolveHoldStatus($supplier) === 'blocked';
    }

    public function blocksPosting(?Supplier $supplier): bool
    {
        return in_array($this->resolveHoldStatus($supplier), ['hold', 'blocked'], true);
    }

    public function blocksPayment(?Supplier $supplier): bool
    {
        return in_array($this->resolveHoldStatus($supplier), ['hold', 'blocked'], true);
    }

    public function exceedsApprovalThreshold(?Supplier $supplier, float $amount): bool
    {
        if (! $supplier || $supplier->approval_threshold === null) {
            return false;
        }

        return $amount > (float) $supplier->approval_threshold;
    }

    public function preferredPaymentMethod(?Supplier $supplier): ?string
    {
        $method = trim((string) ($supplier?->preferred_payment_method ?? ''));

        return $method !== '' ? $method : null;
    }

    public function draftBlockedMessage(?Supplier $supplier): string
    {
        return $this->resolveHoldStatus($supplier) === 'blocked'
            ? __('This supplier is blocked and cannot be used for new AP documents.')
            : __('This supplier cannot be used for new AP documents.');
    }

    public function postingBlockedMessage(?Supplier $supplier): string
    {
        return match ($this->resolveHoldStatus($supplier)) {
            'hold' => __('This supplier is on hold. AP documents cannot be posted until the hold is cleared.'),
            'blocked' => __('This supplier is blocked. AP documents cannot be posted.'),
            default => __('This supplier cannot be posted.'),
        };
    }

    public function paymentBlockedMessage(?Supplier $supplier): string
    {
        return match ($this->resolveHoldStatus($supplier)) {
            'hold' => __('This supplier is on hold. Payments are blocked until the hold is cleared.'),
            'blocked' => __('This supplier is blocked. Payments cannot be created.'),
            default => __('Payments are blocked for this supplier.'),
        };
    }

    public function draftWarning(?Supplier $supplier): ?string
    {
        return match ($this->resolveHoldStatus($supplier)) {
            'hold' => __('This supplier is on hold. Drafts can be prepared, but posting and payment are blocked until the hold is cleared.'),
            'blocked' => $this->draftBlockedMessage($supplier),
            default => null,
        };
    }

    public function assertCanCreateDraft(?Supplier $supplier, string $field = 'supplier_id'): void
    {
        if ($this->blocksDraft($supplier)) {
            throw ValidationException::withMessages([
                $field => $this->draftBlockedMessage($supplier),
            ]);
        }
    }

    public function assertCanPost(?Supplier $supplier, string $field = 'supplier_id'): void
    {
        if ($this->blocksPosting($supplier)) {
            throw ValidationException::withMessages([
                $field => $this->postingBlockedMessage($supplier),
            ]);
        }
    }

    public function assertCanPay(?Supplier $supplier, string $field = 'supplier_id'): void
    {
        if ($this->blocksPayment($supplier)) {
            throw ValidationException::withMessages([
                $field => $this->paymentBlockedMessage($supplier),
            ]);
        }
    }
}
