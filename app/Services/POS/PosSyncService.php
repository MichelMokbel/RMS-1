<?php

namespace App\Services\POS;

use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PettyCashExpense;
use App\Models\PosShift;
use App\Models\PosSyncEvent;
use App\Models\RestaurantArea;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableSession;
use App\Services\POS\Exceptions\PosSyncException;
use App\Services\AP\ApAllocationService;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use App\Services\Ledger\SubledgerService;
use App\Services\PettyCash\PettyCashExpenseWorkflowService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosSyncService
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_APPLIED = 'applied';
    private const STATUS_FAILED = 'failed'; // retryable
    private const STATUS_REJECTED = 'rejected'; // terminal (deterministic)

    private const ERROR_INCOMPLETE_PROCESSING = 'INCOMPLETE_PROCESSING';
    private const ERROR_VALIDATION = 'VALIDATION_ERROR';
    private const ERROR_UNSUPPORTED_TYPE = 'UNSUPPORTED_TYPE';
    private const ERROR_SERVER = 'SERVER_ERROR';

    public function __construct(
        protected ArInvoiceService $invoices,
        protected ArAllocationService $allocations,
        protected ArPaymentService $arPayments,
        protected ApAllocationService $apAllocations,
        protected PettyCashExpenseWorkflowService $pettyCashWorkflow,
        protected SubledgerService $subledger,
        protected PosBootstrapService $bootstrap,
    ) {
    }

    public function sync($terminal, $user, string $deviceId, ?string $lastPulledAt, array $events): array
    {
        $acks = [];

        foreach ($events as $event) {
            $acks[] = $this->applyOneEvent(
                terminal: $terminal,
                user: $user,
                deviceId: $deviceId,
                event: $event
            );
        }

        $deltas = $this->bootstrap->bootstrap($terminal, $lastPulledAt);
        unset($deltas['terminal']);
        unset($deltas['settings']);

        return [
            'acks' => $acks,
            'deltas' => $deltas,
            'server_timestamp' => now()->utc()->toISOString(),
        ];
    }

    private function applyOneEvent($terminal, $user, string $deviceId, array $event): array
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $clientUuid = (string) ($event['client_uuid'] ?? '');
        $payload = (array) ($event['payload'] ?? []);

        return $this->processEvent(
            terminal: $terminal,
            user: $user,
            deviceId: $deviceId,
            eventId: $eventId,
            type: $type,
            clientUuid: $clientUuid,
            payload: $payload,
        );
    }

    private function processEvent($terminal, $user, string $deviceId, string $eventId, string $type, string $clientUuid, array $payload): array
    {
        try {
            return DB::transaction(function () use ($terminal, $user, $deviceId, $eventId, $type, $clientUuid, $payload) {
                $syncEvent = $this->lockOrCreateSyncEvent(
                    terminalId: (int) $terminal->id,
                    eventId: $eventId,
                    clientUuid: $clientUuid,
                    type: $type,
                );

                // Keep tracking columns current on every attempt.
                $syncEvent->terminal_id = (int) $terminal->id;
                $syncEvent->event_id = $eventId;
                $syncEvent->type = $type;
                $syncEvent->save();

                $knownStatuses = [
                    self::STATUS_PENDING,
                    self::STATUS_PROCESSING,
                    self::STATUS_APPLIED,
                    self::STATUS_FAILED,
                    self::STATUS_REJECTED,
                ];
                if (! in_array((string) $syncEvent->status, $knownStatuses, true)) {
                    $syncEvent->status = self::STATUS_FAILED;
                    $syncEvent->error_code = self::ERROR_INCOMPLETE_PROCESSING;
                    $syncEvent->error_message = 'Unknown sync status; event must be retried.';
                    $syncEvent->applied_at = null;
                    $syncEvent->save();

                    return $this->ackError($eventId, self::ERROR_INCOMPLETE_PROCESSING, (string) $syncEvent->error_message);
                }

                if ($syncEvent->status === self::STATUS_APPLIED) {
                    return $this->ackFromAppliedRow($eventId, $syncEvent);
                }

                if ($syncEvent->status === self::STATUS_REJECTED) {
                    return $this->ackFromRejectedRow($eventId, $syncEvent);
                }

                if ($syncEvent->status === self::STATUS_PROCESSING) {
                    // A previous attempt crashed/timed-out. Never ACK ok=true unless completion is proven.
                    if ($syncEvent->server_entity_type && $syncEvent->server_entity_id) {
                        if (! $syncEvent->applied_at) {
                            $syncEvent->applied_at = now();
                        }
                        $syncEvent->status = self::STATUS_APPLIED;
                        $syncEvent->error_code = null;
                        $syncEvent->error_message = null;
                        $syncEvent->save();

                        return $this->ackFromAppliedRow($eventId, $syncEvent);
                    }

                    // No proof of completion: treat as retryable failure.
                    $syncEvent->status = self::STATUS_FAILED;
                    $syncEvent->error_code = self::ERROR_INCOMPLETE_PROCESSING;
                    $syncEvent->error_message = 'Event was left in processing state without a persisted entity.';
                    $syncEvent->applied_at = null;
                    $syncEvent->save();

                    return $this->ackError($eventId, self::ERROR_INCOMPLETE_PROCESSING, (string) $syncEvent->error_message);
                }

                if ($syncEvent->status === self::STATUS_FAILED) {
                    // If completion is already proven, finalize and ACK ok=true.
                    if ($syncEvent->server_entity_type && $syncEvent->server_entity_id) {
                        if (! $syncEvent->applied_at) {
                            $syncEvent->applied_at = now();
                        }
                        $syncEvent->status = self::STATUS_APPLIED;
                        $syncEvent->error_code = null;
                        $syncEvent->error_message = null;
                        $syncEvent->save();

                        return $this->ackFromAppliedRow($eventId, $syncEvent);
                    }

                    // Retryable failures may be re-processed.
                    $syncEvent->status = self::STATUS_PENDING;
                    $syncEvent->error_code = null;
                    $syncEvent->error_message = null;
                    $syncEvent->applied_at = null;
                    $syncEvent->save();
                }

                $supported = [
                    'shift.open',
                    'shift.close',
                    'table_session.open',
                    'table_session.close',
                    'shift.opening_cash.update',
                    'invoice.finalize',
                    'petty_cash.expense.create',
                    'customer.upsert',
                    'category.upsert',
                    'customer.payment.create',
                    'customer.advance.create',
                    'customer.advance.apply',
                    'supplier.payment.create',
                    'restaurant_area.upsert',
                    'restaurant_table.upsert',
                ];

                if (! in_array($type, $supported, true)) {
                    $syncEvent->status = self::STATUS_REJECTED;
                    $syncEvent->error_code = self::ERROR_UNSUPPORTED_TYPE;
                    $syncEvent->error_message = 'Unsupported event type.';
                    $syncEvent->applied_at = null;
                    $syncEvent->save();

                    return $this->ackError($eventId, self::ERROR_UNSUPPORTED_TYPE, (string) $syncEvent->error_message);
                }

                $shapeError = $this->deterministicPayloadShapeError($type, $payload);
                if ($shapeError) {
                    $syncEvent->status = self::STATUS_REJECTED;
                    $syncEvent->error_code = self::ERROR_VALIDATION;
                    $syncEvent->error_message = $shapeError;
                    $syncEvent->applied_at = null;
                    $syncEvent->save();

                    return $this->ackError($eventId, self::ERROR_VALIDATION, $shapeError);
                }

                $syncEvent->status = self::STATUS_PROCESSING;
                $syncEvent->error_code = null;
                $syncEvent->error_message = null;
                $syncEvent->applied_at = null;
                $syncEvent->save();

                $result = match ($type) {
                    'shift.open' => $this->handleShiftOpen($terminal, $user, $deviceId, $payload),
                    'shift.close' => $this->handleShiftClose($terminal, $user, $payload),
                    'table_session.open' => $this->handleTableSessionOpen($terminal, $user, $deviceId, $payload),
                    'table_session.close' => $this->handleTableSessionClose($terminal, $user, $payload),
                    'shift.opening_cash.update' => $this->handleShiftOpeningCashUpdate($terminal, $user, $payload),
                    'invoice.finalize' => $this->handleInvoiceFinalize($terminal, $user, $deviceId, $payload),
                    'petty_cash.expense.create' => $this->handlePettyCashExpenseCreate($terminal, $user, $payload),
                    'customer.upsert' => $this->handleCustomerUpsert($terminal, $user, $payload),
                    'category.upsert' => $this->handleCategoryUpsert($terminal, $user, $payload),
                    'customer.payment.create' => $this->handleCustomerPaymentCreate($terminal, $user, $deviceId, $payload),
                    'customer.advance.create' => $this->handleCustomerAdvanceCreate($terminal, $user, $deviceId, $payload),
                    'customer.advance.apply' => $this->handleCustomerAdvanceApply($terminal, $user, $payload),
                    'supplier.payment.create' => $this->handleSupplierPaymentCreate($terminal, $user, $payload),
                    'restaurant_area.upsert' => $this->handleAreaUpsert($terminal, $user, $payload),
                    'restaurant_table.upsert' => $this->handleTableUpsert($terminal, $user, $payload),
                };

                $entityType = (string) ($result['entity_type'] ?? '');
                $entityId = (int) ($result['entity_id'] ?? 0);
                if ($entityType === '' || $entityId <= 0) {
                    throw new \RuntimeException('POS sync handler did not return a server entity reference.');
                }

                $appliedAt = now();
                $syncEvent->update([
                    'server_entity_type' => $entityType,
                    'server_entity_id' => $entityId,
                    'status' => self::STATUS_APPLIED,
                    'applied_at' => $appliedAt,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                return $this->ackOk($eventId, $entityType, $entityId, $appliedAt);
            }, 3);
        } catch (PosSyncException $e) {
            $this->markEventFailed(
                terminalId: (int) $terminal->id,
                eventId: $eventId,
                clientUuid: $clientUuid,
                type: $type,
                errorCode: (string) $e->errorCode,
                errorMessage: (string) $e->getMessage(),
            );

            return array_merge($this->ackError($eventId, (string) $e->errorCode, (string) $e->getMessage()), $e->data);
        } catch (ValidationException $e) {
            $msg = $this->firstValidationMessage($e);
            $this->markEventFailed(
                terminalId: (int) $terminal->id,
                eventId: $eventId,
                clientUuid: $clientUuid,
                type: $type,
                errorCode: self::ERROR_VALIDATION,
                errorMessage: $msg,
            );

            return $this->ackError($eventId, self::ERROR_VALIDATION, $msg);
        } catch (\Throwable $e) {
            $this->markEventFailed(
                terminalId: (int) $terminal->id,
                eventId: $eventId,
                clientUuid: $clientUuid,
                type: $type,
                errorCode: self::ERROR_SERVER,
                errorMessage: mb_substr((string) $e->getMessage(), 0, 500),
            );

            return $this->ackError($eventId, self::ERROR_SERVER, 'Server error.');
        }
    }

    private function lockOrCreateSyncEvent(int $terminalId, string $eventId, string $clientUuid, string $type): PosSyncEvent
    {
        $row = PosSyncEvent::query()
            ->where('client_uuid', $clientUuid)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        try {
            PosSyncEvent::create([
                'terminal_id' => $terminalId,
                'event_id' => $eventId,
                'client_uuid' => $clientUuid,
                'type' => $type,
                'status' => self::STATUS_PENDING,
                'applied_at' => null,
            ]);
        } catch (QueryException $e) {
            // A concurrent request might have inserted the same client_uuid.
        }

        return PosSyncEvent::query()
            ->where('client_uuid', $clientUuid)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ackFromAppliedRow(string $eventId, PosSyncEvent $row): array
    {
        if (! $row->server_entity_type || ! $row->server_entity_id || ! $row->applied_at) {
            // Corrupt row: never ACK ok=true without a definitive completion proof.
            $row->update([
                'status' => self::STATUS_FAILED,
                'error_code' => self::ERROR_INCOMPLETE_PROCESSING,
                'error_message' => 'Applied status is missing required completion fields.',
                'applied_at' => null,
            ]);

            return $this->ackError($eventId, self::ERROR_INCOMPLETE_PROCESSING, 'Event is incomplete on server; retry.');
        }

        return $this->ackOk(
            eventId: $eventId,
            entityType: (string) $row->server_entity_type,
            entityId: (int) $row->server_entity_id,
            appliedAt: $row->applied_at,
        );
    }

    private function ackFromRejectedRow(string $eventId, PosSyncEvent $row): array
    {
        return $this->ackError(
            eventId: $eventId,
            code: (string) ($row->error_code ?: self::ERROR_VALIDATION),
            message: (string) ($row->error_message ?: 'Rejected.'),
        );
    }

    private function ackError(string $eventId, string $code, string $message): array
    {
        return [
            'event_id' => $eventId,
            'ok' => false,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }

    private function ackOk(string $eventId, string $entityType, int $entityId, Carbon $appliedAt): array
    {
        return [
            'event_id' => $eventId,
            'ok' => true,
            'server_entity_type' => $entityType,
            'server_entity_id' => $entityId,
            'applied_at' => $appliedAt->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }

    private function markEventFailed(int $terminalId, string $eventId, string $clientUuid, string $type, string $errorCode, string $errorMessage): void
    {
        try {
            DB::transaction(function () use ($terminalId, $eventId, $clientUuid, $type, $errorCode, $errorMessage) {
                $row = PosSyncEvent::query()
                    ->where('client_uuid', $clientUuid)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    $row = new PosSyncEvent();
                    $row->terminal_id = $terminalId;
                    $row->event_id = $eventId;
                    $row->client_uuid = $clientUuid;
                    $row->type = $type;
                } else {
                    $row->terminal_id = $terminalId;
                    $row->event_id = $eventId;
                    $row->type = $type;
                }

                $row->status = self::STATUS_FAILED;
                $row->error_code = $errorCode;
                $row->error_message = $errorMessage;
                $row->applied_at = null;
                $row->save();
            }, 3);
        } catch (\Throwable $ignored) {
        }
    }

    private function deterministicPayloadShapeError(string $type, array $payload): ?string
    {
        try {
            match ($type) {
                'shift.open' => Validator::make($payload, [
                    'opening_cash_cents' => ['required', 'integer', 'min:0'],
                    'opened_at' => ['required', 'date'],
                ])->validate(),

                'shift.close' => Validator::make($payload, [
                    'shift_id' => ['required', 'integer', 'min:1'],
                    'closed_at' => ['required', 'date'],
                    'closing_cash_cents' => ['required', 'integer', 'min:0'],
                    'closing_card_cents' => ['sometimes', 'integer', 'min:0'],
                    'expected_cash_cents' => ['sometimes', 'nullable', 'integer'],
                ])->validate(),

                'shift.opening_cash.update' => Validator::make($payload, [
                    'shift_id' => ['required', 'integer', 'min:1'],
                    'opening_cash_cents' => ['required', 'integer', 'min:0'],
                    'adjusted_at' => ['sometimes', 'nullable', 'date'],
                    'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
                ])->validate(),

                'table_session.open' => Validator::make($payload, [
                    'table_id' => ['required', 'integer', 'min:1'],
                    'opened_at' => ['required', 'date'],
                    'guests' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
                    'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
                    'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                ])->validate(),

                'table_session.close' => Validator::make($payload, [
                    'table_session_id' => ['required', 'integer', 'min:1'],
                    'closed_at' => ['required', 'date'],
                ])->validate(),

                'invoice.finalize' => Validator::make($payload, [
                    'client_uuid' => ['required', 'uuid'],
                    'pos_reference' => ['required', 'string', 'max:50'],
                    'payment_type' => ['required', 'in:cash,card,mixed,credit'],
                    'customer_id' => ['required', 'integer', 'min:1'],
                    'issue_date' => ['required', 'date_format:Y-m-d'],
                    'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'restaurant_table_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'table_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'lines' => ['required', 'array', 'min:1'],
                    'lines.*.menu_item_id' => ['required', 'integer', 'min:1'],
                    'lines.*.qty' => ['required'],
                    'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
                    'lines.*.line_discount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
                    'lines.*.line_total_cents' => ['required', 'integer', 'min:0'],
                    'totals' => ['required', 'array'],
                    'totals.subtotal_cents' => ['required', 'integer', 'min:0'],
                    'totals.discount_cents' => ['required', 'integer', 'min:0'],
                    'totals.tax_cents' => ['required', 'integer', 'min:0'],
                    'totals.total_cents' => ['required', 'integer', 'min:0'],
                    'payments' => ['sometimes', 'array'],
                    'payments.*.client_uuid' => ['required_with:payments', 'uuid'],
                    'payments.*.method' => ['required_with:payments', 'string', 'max:30'],
                    'payments.*.amount_cents' => ['required_with:payments', 'integer', 'min:0'],
                    'payments.*.received_at' => ['sometimes', 'nullable', 'date'],
                ])->validate(),

                'petty_cash.expense.create' => Validator::make($payload, [
                    'client_uuid' => ['required', 'uuid'],
                    'wallet_id' => ['required', 'integer', 'min:1'],
                    'category_id' => ['required', 'integer', 'min:1'],
                    'expense_date' => ['required', 'date_format:Y-m-d'],
                    'amount_cents' => ['required', 'integer', 'min:1'],
                    'description' => ['required', 'string', 'max:255'],
                    'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                ])->validate(),

                'customer.upsert' => Validator::make($payload, [
                    'customer' => ['required', 'array'],
                    'customer.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'customer.name' => ['required', 'string', 'max:255'],
                    'customer.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
                    'customer.email' => ['sometimes', 'nullable', 'email', 'max:255'],
                    'customer.updated_at' => ['sometimes', 'nullable', 'date'],
                ])->validate(),

                'category.upsert' => Validator::make($payload, [
                    'category' => ['required', 'array'],
                    'category.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'category.name' => ['required', 'string', 'max:255'],
                    'category.description' => ['sometimes', 'nullable', 'string'],
                    'category.parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'category.updated_at' => ['sometimes', 'nullable', 'date'],
                ])->validate(),

                'customer.payment.create' => Validator::make($payload, [
                    'payment' => ['required', 'array'],
                    'payment.client_uuid' => ['required', 'uuid'],
                    'payment.customer_id' => ['required', 'integer', 'min:1'],
                    'payment.amount_cents' => ['required', 'integer', 'min:1'],
                    'payment.method' => ['required', 'string', 'in:cash,card,online,bank,voucher'],
                    'payment.received_at' => ['sometimes', 'nullable', 'date'],
                    'payment.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
                    'payment.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
                    'payment.currency' => ['sometimes', 'nullable', 'string', 'max:10'],
                    'payment.pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'allocations' => ['required', 'array', 'min:1'],
                    'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'min:1'],
                    'allocations.*.amount_cents' => ['required_with:allocations', 'integer', 'min:1'],
                ])->validate(),

                'customer.advance.create' => Validator::make($payload, [
                    'payment' => ['required', 'array'],
                    'payment.client_uuid' => ['required', 'uuid'],
                    'payment.customer_id' => ['required', 'integer', 'min:1'],
                    'payment.amount_cents' => ['required', 'integer', 'min:1'],
                    'payment.method' => ['required', 'string', 'in:cash,card,online,bank,voucher'],
                    'payment.received_at' => ['sometimes', 'nullable', 'date'],
                    'payment.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
                    'payment.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
                    'payment.currency' => ['sometimes', 'nullable', 'string', 'max:10'],
                    'payment.pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                ])->validate(),

                'customer.advance.apply' => Validator::make($payload, [
                    'payment_id' => ['required_without:payment_client_uuid', 'integer', 'min:1'],
                    'payment_client_uuid' => ['required_without:payment_id', 'uuid'],
                    'invoice_id' => ['required', 'integer', 'min:1'],
                    'amount_cents' => ['required', 'integer', 'min:1'],
                ])->validate(),

                'supplier.payment.create' => Validator::make($payload, [
                    'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
                    'payment_date' => ['required', 'date'],
                    'amount_cents' => ['required', 'integer', 'min:1'],
                    'payment_method' => ['required', 'string', 'in:cash,bank_transfer,card,cheque,other'],
                    'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
                    'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
                    'allocations' => ['sometimes', 'array'],
                    'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'min:1'],
                    'allocations.*.amount_cents' => ['required_with:allocations', 'integer', 'min:1'],
                ])->validate(),

                'restaurant_area.upsert' => Validator::make($payload, [
                    'area' => ['required', 'array'],
                    'area.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'area.name' => ['required', 'string', 'max:100'],
                    'area.display_order' => ['required', 'integer'],
                    'area.active' => ['sometimes', 'nullable', 'boolean'],
                    'area.updated_at' => ['sometimes', 'nullable', 'date'],
                ])->validate(),

                'restaurant_table.upsert' => Validator::make($payload, [
                    'table' => ['required', 'array'],
                    'table.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'table.area_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'table.code' => ['required', 'string', 'max:50'],
                    'table.name' => ['required', 'string', 'max:100'],
                    'table.capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'table.display_order' => ['required', 'integer'],
                    'table.active' => ['sometimes', 'nullable', 'boolean'],
                    'table.updated_at' => ['sometimes', 'nullable', 'date'],
                ])->validate(),
            };

            return null;
        } catch (ValidationException $e) {
            return $this->firstValidationMessage($e);
        }
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        foreach ($errors as $messages) {
            if (is_array($messages) && isset($messages[0])) {
                return (string) $messages[0];
            }
        }
        return 'Validation error.';
    }

    private function handleShiftOpen($terminal, $user, string $deviceId, array $payload): array
    {
        $v = Validator::make($payload, [
            'opening_cash_cents' => ['required', 'integer', 'min:0'],
            'opened_at' => ['required', 'date'],
        ])->validate();

        $shift = PosShift::create([
            'branch_id' => (int) $terminal->branch_id,
            'terminal_id' => (int) $terminal->id,
            'device_id' => $deviceId,
            'user_id' => (int) $user->id,
            'active' => true,
            'status' => 'open',
            'opening_cash_cents' => (int) $v['opening_cash_cents'],
            'opened_at' => Carbon::parse($v['opened_at']),
            'created_by' => (int) $user->id,
        ]);

        return ['entity_type' => 'pos_shift', 'entity_id' => (int) $shift->id];
    }

    private function handleShiftClose($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'shift_id' => ['required', 'integer', 'min:1'],
            'closed_at' => ['required', 'date'],
            'closing_cash_cents' => ['required', 'integer', 'min:0'],
            'closing_card_cents' => ['sometimes', 'integer', 'min:0'],
            'expected_cash_cents' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        /** @var PosShift $shift */
        $shift = PosShift::query()->whereKey((int) $v['shift_id'])->lockForUpdate()->firstOrFail();

        if (! $shift->isOpen()) {
            return ['entity_type' => 'pos_shift', 'entity_id' => (int) $shift->id];
        }

        $cashPayments = (int) Payment::query()
            ->whereNull('voided_at')
            ->where('pos_shift_id', $shift->id)
            ->where('method', 'cash')
            ->sum('amount_cents');

        $expected = (int) ($v['expected_cash_cents'] ?? ((int) $shift->opening_cash_cents + $cashPayments));
        $counted = (int) $v['closing_cash_cents'];
        $cardCounted = (int) ($v['closing_card_cents'] ?? 0);
        $variance = $counted - $expected;

        $shift->update([
            'closing_cash_cents' => $counted,
            'closing_card_cents' => $cardCounted,
            'expected_cash_cents' => $expected,
            'variance_cents' => $variance,
            'closed_at' => Carbon::parse($v['closed_at']),
            'closed_by' => (int) $user->id,
            'status' => 'closed',
            'active' => null,
        ]);

        return ['entity_type' => 'pos_shift', 'entity_id' => (int) $shift->id];
    }

    private function handleShiftOpeningCashUpdate($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'shift_id' => ['required', 'integer', 'min:1'],
            'opening_cash_cents' => ['required', 'integer', 'min:0'],
            'adjusted_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        /** @var PosShift $shift */
        $shift = PosShift::query()->whereKey((int) $v['shift_id'])->lockForUpdate()->firstOrFail();
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Shift is not open.']);
        }

        $shift->update([
            'opening_cash_cents' => (int) $v['opening_cash_cents'],
            'opening_cash_adjusted_at' => isset($v['adjusted_at']) ? Carbon::parse($v['adjusted_at']) : now(),
            'opening_cash_adjusted_by' => (int) $user->id,
            'opening_cash_adjustment_reason' => $v['reason'] ?? null,
        ]);

        return ['entity_type' => 'pos_shift', 'entity_id' => (int) $shift->id];
    }

    private function handleTableSessionOpen($terminal, $user, string $deviceId, array $payload): array
    {
        $v = Validator::make($payload, [
            'table_id' => ['required', 'integer', 'min:1'],
            'opened_at' => ['required', 'date'],
            'guests' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        // Lock table row to serialize concurrent opens for the same table.
        $table = RestaurantTable::query()->whereKey((int) $v['table_id'])->lockForUpdate()->firstOrFail();
        if ((int) $table->branch_id !== (int) $terminal->branch_id) {
            throw ValidationException::withMessages(['table_id' => 'Table branch mismatch.']);
        }

        $existing = RestaurantTableSession::query()
            ->where('table_id', (int) $table->id)
            ->where('active', 1)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw new PosSyncException(
                errorCode: 'TABLE_ALREADY_OPEN',
                message: 'Table is already open.',
                data: [
                    'existing_table_session_id' => (int) $existing->id,
                    'existing_terminal_id' => $existing->terminal_id ? (int) $existing->terminal_id : null,
                    'existing_device_id' => $existing->device_id,
                ],
            );
        }

        try {
            $session = RestaurantTableSession::create([
                'branch_id' => (int) $terminal->branch_id,
                'table_id' => (int) $table->id,
                'status' => 'open',
                'active' => true,
                'opened_by' => (int) $user->id,
                'device_id' => $deviceId,
                'terminal_id' => (int) $terminal->id,
                'pos_shift_id' => isset($v['pos_shift_id']) ? (int) $v['pos_shift_id'] : null,
                'opened_at' => Carbon::parse($v['opened_at']),
                'guests' => $v['guests'] ?? null,
                'notes' => $v['notes'] ?? null,
            ]);
        } catch (QueryException $e) {
            $existing = RestaurantTableSession::query()
                ->where('table_id', (int) $table->id)
                ->where('active', 1)
                ->first();

            throw new PosSyncException(
                errorCode: 'TABLE_ALREADY_OPEN',
                message: 'Table is already open.',
                data: [
                    'existing_table_session_id' => $existing?->id ? (int) $existing->id : null,
                    'existing_terminal_id' => $existing?->terminal_id ? (int) $existing->terminal_id : null,
                    'existing_device_id' => $existing?->device_id,
                ],
                previous: $e,
            );
        }

        return ['entity_type' => 'restaurant_table_session', 'entity_id' => (int) $session->id];
    }

    private function handleTableSessionClose($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'table_session_id' => ['required', 'integer', 'min:1'],
            'closed_at' => ['required', 'date'],
        ])->validate();

        $session = RestaurantTableSession::query()
            ->whereKey((int) $v['table_session_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if (! $session->active || $session->status === 'closed') {
            return ['entity_type' => 'restaurant_table_session', 'entity_id' => (int) $session->id];
        }

        $session->update([
            'status' => 'closed',
            'active' => false,
            'closed_at' => Carbon::parse($v['closed_at']),
        ]);

        return ['entity_type' => 'restaurant_table_session', 'entity_id' => (int) $session->id];
    }

    private function handleInvoiceFinalize($terminal, $user, string $deviceId, array $payload): array
    {
        $v = Validator::make($payload, [
            'pos_reference' => ['required', 'string', 'regex:/^T\\d{2}-\\d{8}-\\d{6}$/'],
            'payment_type' => ['required', 'string', 'in:cash,card,credit,mixed'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'restaurant_table_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'table_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.menu_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'regex:/^\\d+(\\.\\d{1,3})?$/'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'lines.*.line_discount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines.*.line_total_cents' => ['required', 'integer', 'min:0'],
            'totals' => ['required', 'array'],
            'totals.subtotal_cents' => ['required', 'integer', 'min:0'],
            'totals.discount_cents' => ['required', 'integer', 'min:0'],
            'totals.tax_cents' => ['required', 'integer', 'min:0'],
            'totals.total_cents' => ['required', 'integer', 'min:0'],
            'payments' => ['sometimes', 'array'],
            'payments.*.client_uuid' => ['required_with:payments', 'uuid'],
            'payments.*.method' => ['required_with:payments', 'string', 'in:cash,card,online,bank,voucher'],
            'payments.*.amount_cents' => ['required_with:payments', 'integer', 'min:1'],
            'payments.*.received_at' => ['sometimes', 'nullable', 'date'],
            'payments.*.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ])->validate();

        if ($v['payment_type'] === 'credit' && ! empty($v['payments'] ?? [])) {
            throw ValidationException::withMessages(['payments' => 'Credit invoices must not include payments.']);
        }
        if ($v['payment_type'] !== 'credit' && empty($v['payments'] ?? [])) {
            throw ValidationException::withMessages(['payments' => 'Payments are required.']);
        }

        if (! str_starts_with($v['pos_reference'], (string) $terminal->code.'-')) {
            throw ValidationException::withMessages(['pos_reference' => 'Terminal code mismatch.']);
        }

        $invoiceClientUuid = (string) ($payload['client_uuid'] ?? '');
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $invoiceClientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => 'Invoice client_uuid is required.']);
        }

        $existing = ArInvoice::query()->where('client_uuid', $invoiceClientUuid)->first();
        if ($existing) {
            return ['entity_type' => 'ar_invoice', 'entity_id' => (int) $existing->id];
        }

        $existingByRef = ArInvoice::query()
            ->where('branch_id', (int) $terminal->branch_id)
            ->where('pos_reference', $v['pos_reference'])
            ->first();

        if ($existingByRef) {
            return ['entity_type' => 'ar_invoice', 'entity_id' => (int) $existingByRef->id];
        }

        $customer = Customer::find((int) $v['customer_id']);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Customer not found.']);
        }

        $items = [];
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($v['lines'] as $line) {
            $menuItem = MenuItem::query()->whereKey((int) $line['menu_item_id'])->first();
            if (! $menuItem || ! $menuItem->isActive()) {
                throw ValidationException::withMessages(['lines' => 'Invalid menu item.']);
            }

            $qtyStr = is_string($line['qty']) ? $line['qty'] : (string) $line['qty'];
            $qtyMilli = MinorUnits::parseQtyMilli($qtyStr);

            $unit = (int) $line['unit_price_cents'];
            $lineSubtotal = MinorUnits::mulQty($unit, $qtyMilli);
            $lineDiscount = (int) ($line['line_discount_cents'] ?? 0);
            $lineTax = 0;
            $lineTotal = (int) $line['line_total_cents'];

            $expectedLineTotal = max(0, $lineSubtotal - $lineDiscount + $lineTax);
            if ($lineTotal !== $expectedLineTotal) {
                throw ValidationException::withMessages(['lines' => 'Line totals mismatch.']);
            }

            $subtotal += $lineSubtotal;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $total += $lineTotal;

            $items[] = [
                'description' => (string) $menuItem->name,
                'qty' => $qtyStr,
                'unit' => (string) ($menuItem->unit ?? 'each'),
                'unit_price_cents' => $unit,
                'discount_cents' => $lineDiscount,
                'tax_cents' => $lineTax,
                'line_total_cents' => $lineTotal,
                'sellable_type' => MenuItem::class,
                'sellable_id' => (int) $menuItem->id,
                'name_snapshot' => (string) $menuItem->name,
                'sku_snapshot' => (string) ($menuItem->code ?? ''),
                'meta' => [
                    'pos' => true,
                ],
            ];
        }

        if ($subtotal !== (int) $v['totals']['subtotal_cents']
            || $discount !== (int) $v['totals']['discount_cents']
            || $tax !== (int) $v['totals']['tax_cents']
            || $total !== (int) $v['totals']['total_cents']) {
            throw ValidationException::withMessages(['totals' => 'Totals mismatch.']);
        }

        $invoice = $this->invoices->createDraft(
            branchId: (int) $terminal->branch_id,
            customerId: (int) $customer->id,
            items: $items,
            actorId: (int) $user->id,
            currency: (string) config('pos.currency', 'QAR'),
            posReference: (string) $v['pos_reference'],
            source: 'pos',
            sourceSaleId: null,
            type: 'invoice',
            issueDate: (string) $v['issue_date'],
            paymentType: (string) $v['payment_type'],
        );

        $invoice->update([
            'terminal_id' => (int) $terminal->id,
            'pos_shift_id' => $v['pos_shift_id'] ?? null,
            'client_uuid' => $invoiceClientUuid,
            'restaurant_table_id' => $v['restaurant_table_id'] ?? null,
            'table_session_id' => $v['table_session_id'] ?? null,
            'meta' => [
                'device_id' => $deviceId,
            ],
            'updated_by' => (int) $user->id,
        ]);

        $invoice = $this->invoices->issue($invoice->fresh(['items']), (int) $user->id);

        if ($v['payment_type'] !== 'credit') {
            $payments = $v['payments'] ?? [];
            $sumPayments = 0;
            foreach ($payments as $p) {
                $sumPayments += (int) $p['amount_cents'];
            }
            if ($sumPayments !== (int) $invoice->total_cents) {
                throw ValidationException::withMessages(['payments' => 'Payment total must equal invoice total.']);
            }

            $remaining = (int) $invoice->total_cents;
            foreach ($payments as $p) {
                if ($remaining <= 0) {
                    break;
                }

                $paymentUuid = (string) $p['client_uuid'];
                $amount = (int) $p['amount_cents'];
                $alloc = min($remaining, $amount);
                $remaining -= $alloc;

                $payment = Payment::create([
                    'branch_id' => (int) $invoice->branch_id,
                    'customer_id' => (int) $invoice->customer_id,
                    'client_uuid' => $paymentUuid,
                    'terminal_id' => (int) $terminal->id,
                    'pos_shift_id' => $invoice->pos_shift_id,
                    'source' => 'pos',
                    'method' => (string) $p['method'],
                    'amount_cents' => $amount,
                    'currency' => (string) ($invoice->currency ?: config('pos.currency')),
                    'received_at' => isset($p['received_at']) ? Carbon::parse($p['received_at']) : now(),
                    'reference' => $p['reference'] ?? null,
                    'created_by' => (int) $user->id,
                ]);

                if ($alloc > 0) {
                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => ArInvoice::class,
                        'allocatable_id' => $invoice->id,
                        'amount_cents' => $alloc,
                    ]);
                }

                $this->subledger->recordArPaymentReceived($payment->fresh(), $alloc, $amount - $alloc, (int) $user->id);
            }

            $this->invoices->recalc($invoice->fresh(['items']));
            $this->allocations->recalcStatus($invoice->fresh());
        }

        return ['entity_type' => 'ar_invoice', 'entity_id' => (int) $invoice->id];
    }

    private function handlePettyCashExpenseCreate($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'wallet_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        $clientUuid = (string) ($payload['client_uuid'] ?? '');
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => 'Expense client_uuid is required.']);
        }

        $existing = PettyCashExpense::query()->where('client_uuid', $clientUuid)->first();
        if ($existing) {
            return ['entity_type' => 'petty_cash_expense', 'entity_id' => (int) $existing->id];
        }

        $amountDecimal = MinorUnits::format((int) $v['amount_cents'], MinorUnits::posScale(), false);

        $expense = PettyCashExpense::create([
            'client_uuid' => $clientUuid,
            'terminal_id' => (int) $terminal->id,
            'pos_shift_id' => $v['pos_shift_id'] ?? null,
            'wallet_id' => (int) $v['wallet_id'],
            'category_id' => (int) $v['category_id'],
            'expense_date' => (string) $v['expense_date'],
            'description' => (string) $v['description'],
            'amount' => $amountDecimal,
            'tax_amount' => '0.00',
            'total_amount' => $amountDecimal,
            'status' => 'draft',
            'submitted_by' => (int) $user->id,
            'created_at' => now(),
        ]);

        $expense = $this->pettyCashWorkflow->submit($expense, (int) $user->id);

        return ['entity_type' => 'petty_cash_expense', 'entity_id' => (int) $expense->id];
    }

    private function handleCustomerUpsert($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'customer' => ['required', 'array'],
            'customer.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer.updated_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $c = (array) $v['customer'];
        $incomingUpdatedAt = isset($c['updated_at']) ? Carbon::parse($c['updated_at']) : null;

        if (! empty($c['id'])) {
            $customer = Customer::query()->whereKey((int) $c['id'])->lockForUpdate()->firstOrFail();
            if ($incomingUpdatedAt && $customer->updated_at && $customer->updated_at->greaterThan($incomingUpdatedAt)) {
                return ['entity_type' => 'customer', 'entity_id' => (int) $customer->id];
            }

            $customer->update([
                'name' => (string) $c['name'],
                'phone' => $c['phone'] ?? null,
                'email' => $c['email'] ?? null,
                'updated_by' => (int) $user->id,
            ]);

            return ['entity_type' => 'customer', 'entity_id' => (int) $customer->id];
        }

        $customer = Customer::create([
            'name' => (string) $c['name'],
            'phone' => $c['phone'] ?? null,
            'email' => $c['email'] ?? null,
            'is_active' => 1,
            'created_by' => (int) $user->id,
            'updated_by' => (int) $user->id,
        ]);

        return ['entity_type' => 'customer', 'entity_id' => (int) $customer->id];
    }

    private function handleCategoryUpsert($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'category' => ['required', 'array'],
            'category.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category.name' => ['required', 'string', 'max:255'],
            'category.description' => ['sometimes', 'nullable', 'string'],
            'category.parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category.updated_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $c = (array) $v['category'];
        $incomingUpdatedAt = isset($c['updated_at']) ? Carbon::parse($c['updated_at']) : null;
        $parentId = isset($c['parent_id']) ? (int) $c['parent_id'] : null;

        if ($parentId && ! Category::find($parentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Parent category not found.']);
        }

        if (! empty($c['id'])) {
            $category = Category::query()->whereKey((int) $c['id'])->lockForUpdate()->firstOrFail();
            if ($incomingUpdatedAt && $category->updated_at && $category->updated_at->greaterThan($incomingUpdatedAt)) {
                return ['entity_type' => 'category', 'entity_id' => (int) $category->id];
            }

            if ($parentId) {
                if ($parentId === (int) $category->id) {
                    throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
                }
                if ($category->wouldCreateCycle($parentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Parent selection creates a cycle.']);
                }
            }

            $category->update([
                'name' => (string) $c['name'],
                'description' => $c['description'] ?? null,
                'parent_id' => $parentId ?: null,
            ]);

            return ['entity_type' => 'category', 'entity_id' => (int) $category->id];
        }

        $category = Category::create([
            'name' => (string) $c['name'],
            'description' => $c['description'] ?? null,
            'parent_id' => $parentId ?: null,
        ]);

        return ['entity_type' => 'category', 'entity_id' => (int) $category->id];
    }

    private function handleCustomerPaymentCreate($terminal, $user, string $deviceId, array $payload): array
    {
        $v = Validator::make($payload, [
            'payment' => ['required', 'array'],
            'payment.client_uuid' => ['required', 'uuid'],
            'payment.customer_id' => ['required', 'integer', 'min:1'],
            'payment.amount_cents' => ['required', 'integer', 'min:1'],
            'payment.method' => ['required', 'string', 'in:cash,card,online,bank,voucher'],
            'payment.received_at' => ['sometimes', 'nullable', 'date'],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'payment.currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'payment.pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'min:1'],
            'allocations.*.amount_cents' => ['required_with:allocations', 'integer', 'min:1'],
        ])->validate();

        $p = (array) $v['payment'];
        $paymentUuid = (string) $p['client_uuid'];

        $existing = Payment::query()->where('client_uuid', $paymentUuid)->first();
        if ($existing) {
            return ['entity_type' => 'payment', 'entity_id' => (int) $existing->id];
        }

        $posShiftId = isset($p['pos_shift_id']) ? (int) $p['pos_shift_id'] : null;
        if ($posShiftId) {
            $shift = PosShift::query()->whereKey($posShiftId)->first();
            if (! $shift) {
                throw ValidationException::withMessages(['payment.pos_shift_id' => 'Shift not found.']);
            }
            if ((int) $shift->branch_id !== (int) $terminal->branch_id) {
                throw ValidationException::withMessages(['payment.pos_shift_id' => 'Shift branch mismatch.']);
            }
        }

        $payment = $this->arPayments->createPaymentWithAllocations([
            'client_uuid' => $paymentUuid,
            'terminal_id' => (int) $terminal->id,
            'pos_shift_id' => $posShiftId,
            'customer_id' => (int) $p['customer_id'],
            'branch_id' => (int) $terminal->branch_id,
            'amount_cents' => (int) $p['amount_cents'],
            'method' => (string) $p['method'],
            'currency' => $p['currency'] ?? null,
            'received_at' => $p['received_at'] ?? null,
            'reference' => $p['reference'] ?? null,
            'notes' => $p['notes'] ?? null,
            'allocations' => $v['allocations'] ?? [],
        ], (int) $user->id);

        return ['entity_type' => 'payment', 'entity_id' => (int) $payment->id];
    }

    private function handleCustomerAdvanceCreate($terminal, $user, string $deviceId, array $payload): array
    {
        $v = Validator::make($payload, [
            'payment' => ['required', 'array'],
            'payment.client_uuid' => ['required', 'uuid'],
            'payment.customer_id' => ['required', 'integer', 'min:1'],
            'payment.amount_cents' => ['required', 'integer', 'min:1'],
            'payment.method' => ['required', 'string', 'in:cash,card,online,bank,voucher'],
            'payment.received_at' => ['sometimes', 'nullable', 'date'],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'payment.currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'payment.pos_shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        $p = (array) $v['payment'];
        $paymentUuid = (string) $p['client_uuid'];

        $existing = Payment::query()->where('client_uuid', $paymentUuid)->first();
        if ($existing) {
            return ['entity_type' => 'payment', 'entity_id' => (int) $existing->id];
        }

        $posShiftId = isset($p['pos_shift_id']) ? (int) $p['pos_shift_id'] : null;
        if ($posShiftId) {
            $shift = PosShift::query()->whereKey($posShiftId)->first();
            if (! $shift) {
                throw ValidationException::withMessages(['payment.pos_shift_id' => 'Shift not found.']);
            }
            if ((int) $shift->branch_id !== (int) $terminal->branch_id) {
                throw ValidationException::withMessages(['payment.pos_shift_id' => 'Shift branch mismatch.']);
            }
        }

        $payment = $this->arPayments->createAdvancePayment(
            customerId: (int) $p['customer_id'],
            branchId: (int) $terminal->branch_id,
            amountCents: (int) $p['amount_cents'],
            method: (string) $p['method'],
            receivedAt: $p['received_at'] ?? null,
            reference: $p['reference'] ?? null,
            notes: $p['notes'] ?? null,
            actorId: (int) $user->id,
            clientUuid: $paymentUuid,
            terminalId: (int) $terminal->id,
            posShiftId: $posShiftId,
            currency: $p['currency'] ?? null,
        );

        return ['entity_type' => 'payment', 'entity_id' => (int) $payment->id];
    }

    private function handleCustomerAdvanceApply($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'payment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'payment_client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'invoice_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:1'],
        ])->validate();

        $paymentId = isset($v['payment_id']) ? (int) $v['payment_id'] : 0;
        $paymentClientUuid = isset($v['payment_client_uuid']) ? (string) $v['payment_client_uuid'] : null;
        if ($paymentId <= 0 && ! $paymentClientUuid) {
            throw ValidationException::withMessages(['payment_id' => 'Payment identifier is required.']);
        }

        $allocation = $this->arPayments->applyExistingPaymentToInvoice(
            paymentId: $paymentId,
            invoiceId: (int) $v['invoice_id'],
            amountCents: (int) $v['amount_cents'],
            actorId: (int) $user->id,
            paymentClientUuid: $paymentClientUuid,
        );

        return ['entity_type' => 'payment_allocation', 'entity_id' => (int) $allocation->id];
    }

    private function handleSupplierPaymentCreate($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,card,cheque,other'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'min:1'],
            'allocations.*.amount_cents' => ['required_with:allocations', 'integer', 'min:1'],
        ])->validate();

        $amount = MinorUnits::format((int) $v['amount_cents'], MinorUnits::posScale(), false);
        $allocations = [];
        foreach (($v['allocations'] ?? []) as $row) {
            $allocations[] = [
                'invoice_id' => (int) $row['invoice_id'],
                'allocated_amount' => MinorUnits::format((int) $row['amount_cents'], MinorUnits::posScale(), false),
            ];
        }

        $payment = $this->apAllocations->createPaymentWithAllocations([
            'supplier_id' => (int) $v['supplier_id'],
            'payment_date' => Carbon::parse($v['payment_date'])->toDateString(),
            'amount' => $amount,
            'payment_method' => (string) $v['payment_method'],
            'reference' => $v['reference'] ?? null,
            'notes' => $v['notes'] ?? null,
            'allocations' => $allocations,
        ], (int) $user->id);

        return ['entity_type' => 'ap_payment', 'entity_id' => (int) $payment->id];
    }

    private function handleAreaUpsert($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'area' => ['required', 'array'],
            'area.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'area.name' => ['required', 'string', 'max:100'],
            'area.display_order' => ['required', 'integer'],
            'area.active' => ['sometimes', 'nullable', 'boolean'],
            'area.updated_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $a = (array) $v['area'];
        $incomingUpdatedAt = isset($a['updated_at']) ? Carbon::parse($a['updated_at']) : null;

        if (! empty($a['id'])) {
            $area = RestaurantArea::query()->whereKey((int) $a['id'])->lockForUpdate()->firstOrFail();

            if ($incomingUpdatedAt && $area->updated_at && $area->updated_at->greaterThan($incomingUpdatedAt)) {
                return ['entity_type' => 'restaurant_area', 'entity_id' => (int) $area->id];
            }

            $area->update([
                'name' => (string) $a['name'],
                'display_order' => (int) $a['display_order'],
                'active' => (bool) ($a['active'] ?? true),
            ]);

            return ['entity_type' => 'restaurant_area', 'entity_id' => (int) $area->id];
        }

        $area = RestaurantArea::create([
            'branch_id' => (int) $terminal->branch_id,
            'name' => (string) $a['name'],
            'display_order' => (int) $a['display_order'],
            'active' => (bool) ($a['active'] ?? true),
        ]);

        return ['entity_type' => 'restaurant_area', 'entity_id' => (int) $area->id];
    }

    private function handleTableUpsert($terminal, $user, array $payload): array
    {
        $v = Validator::make($payload, [
            'table' => ['required', 'array'],
            'table.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'table.area_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'table.code' => ['required', 'string', 'max:50'],
            'table.name' => ['required', 'string', 'max:100'],
            'table.capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'table.display_order' => ['required', 'integer'],
            'table.active' => ['sometimes', 'nullable', 'boolean'],
            'table.updated_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $t = (array) $v['table'];
        $incomingUpdatedAt = isset($t['updated_at']) ? Carbon::parse($t['updated_at']) : null;

        // Validate area_id FK if provided.
        if (! empty($t['area_id'])) {
            if (! RestaurantArea::find((int) $t['area_id'])) {
                throw ValidationException::withMessages(['area_id' => 'Area not found.']);
            }
        }

        if (! empty($t['id'])) {
            $table = RestaurantTable::query()->whereKey((int) $t['id'])->lockForUpdate()->firstOrFail();

            if ($incomingUpdatedAt && $table->updated_at && $table->updated_at->greaterThan($incomingUpdatedAt)) {
                return ['entity_type' => 'restaurant_table', 'entity_id' => (int) $table->id];
            }

            $table->update([
                'area_id' => $t['area_id'] ?? null,
                'code' => (string) $t['code'],
                'name' => (string) $t['name'],
                'capacity' => $t['capacity'] ?? null,
                'display_order' => (int) $t['display_order'],
                'active' => (bool) ($t['active'] ?? true),
            ]);

            return ['entity_type' => 'restaurant_table', 'entity_id' => (int) $table->id];
        }

        try {
            $table = RestaurantTable::create([
                'branch_id' => (int) $terminal->branch_id,
                'area_id' => $t['area_id'] ?? null,
                'code' => (string) $t['code'],
                'name' => (string) $t['name'],
                'capacity' => $t['capacity'] ?? null,
                'display_order' => (int) $t['display_order'],
                'active' => (bool) ($t['active'] ?? true),
            ]);
        } catch (QueryException $e) {
            // Duplicate (branch_id, code) unique constraint violation.
            if (str_contains((string) $e->getMessage(), 'Duplicate entry') || (int) $e->getCode() === 23000) {
                throw ValidationException::withMessages(['code' => 'A table with this code already exists in this branch.']);
            }

            throw $e;
        }

        return ['entity_type' => 'restaurant_table', 'entity_id' => (int) $table->id];
    }
}
