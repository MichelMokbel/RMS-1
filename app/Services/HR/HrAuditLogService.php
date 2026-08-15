<?php

namespace App\Services\HR;

use App\Models\HrAuditLog;
use App\Services\HR\Concerns\FiltersModelAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HrAuditLogService
{
    use FiltersModelAttributes;

    /** @var array<int, string> */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'secret', 'token', 'qid', 'passport', 'bank', 'iban',
        'account_number', 'phone', 'email', 'address', 'birth', 'salary',
        'compensation', 'amount', 'storage_path', 'path', 'checksum', 'sha256',
    ];

    /**
     * Append an audit event. Metadata is recursively redacted before storage.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $event,
        ?int $actorId,
        Model|string|null $subject = null,
        array $metadata = [],
        ?int $companyId = null,
    ): HrAuditLog {
        $subjectType = $subject instanceof Model ? $subject::class : $subject;
        $subjectId = $subject instanceof Model && $subject->getKey() ? (int) $subject->getKey() : null;
        $companyId ??= $subject instanceof Model && isset($subject->company_id)
            ? (int) $subject->company_id
            : null;

        $request = app()->runningInConsole() ? null : request();
        $requestId = $this->requestId($request);
        if ($request && ! $request->attributes->has('hr_request_id')) {
            $request->attributes->set('hr_request_id', $requestId);
        }
        $attributes = $this->modelAttributes(HrAuditLog::class, [
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'user_id' => $actorId,
            'event' => Str::limit($event, 120, ''),
            'action' => Str::limit($event, 100, ''),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'auditable_type' => $subjectType,
            'auditable_id' => $subjectId,
            'request_id' => $requestId,
            'metadata' => $this->redact($metadata),
            'payload' => $this->redact($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        /** @var HrAuditLog $log */
        $log = HrAuditLog::query()->create($attributes);

        return $log;
    }

    /** @param array<string, mixed> $metadata */
    private function redact(array $metadata): array
    {
        return collect($metadata)->mapWithKeys(function (mixed $value, string|int $key): array {
            $normalized = Str::snake((string) $key);
            $sensitive = collect(self::SENSITIVE_FRAGMENTS)
                ->contains(fn (string $fragment): bool => str_contains($normalized, $fragment));

            if ($sensitive) {
                return [$key => '[REDACTED]'];
            }

            if (is_array($value)) {
                return [$key => $this->redact($value)];
            }

            if (is_object($value)) {
                return [$key => '[OBJECT '.class_basename($value).']'];
            }

            return [$key => is_string($value) ? Str::limit($value, 1000, '') : $value];
        })->all();
    }

    private function requestId(mixed $request): ?string
    {
        if (! $request) {
            return null;
        }
        $existing = (string) $request->attributes->get('hr_request_id', '');
        if (Str::isUuid($existing)) {
            return $existing;
        }
        $header = (string) $request->header('X-Request-ID', '');

        return Str::isUuid($header) ? $header : (string) Str::uuid();
    }
}
