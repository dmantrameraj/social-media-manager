<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the audit trail.
 *
 * Every value passes through SecretRedactor first, so a token or credential
 * can never be recovered from the log. Runs synchronously: an audit entry that
 * arrives late, or not at all because a queue worker died, is not an audit
 * entry.
 *
 * See docs/10-SECURITY.md §10.
 */
final class AuditLogger
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Authenticatable $actor = null,
        ?int $tenantId = null,
    ): AuditLog {
        $actor ??= $this->resolveActor();

        return AuditLog::query()->forceCreate([
            'tenant_id' => $tenantId
                ?? $auditable?->getAttribute('tenant_id')
                ?? $this->context->idOrNull(),

            'actor_type' => $this->actorType($actor)->value,
            'actor_id' => $actor?->getAuthIdentifier(),
            'impersonator_user_id' => $this->impersonatorId(),

            'action' => $action,

            'auditable_type' => $auditable !== null ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),

            'old_values' => $this->prepare($oldValues),
            'new_values' => $this->prepare($newValues),

            'ip' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
            'request_id' => $this->requestId(),

            'created_at' => now(),
        ]);
    }

    /**
     * Record a model change, capturing only what actually changed.
     *
     * Logging every attribute on every save would make the trail unreadable
     * and would repeatedly re-record unchanged secrets.
     *
     * TIMING MATTERS. Laravel calls syncOriginal() in finishSave(), so once
     * save() has returned, getRawOriginal() holds the NEW values. Call this
     * either:
     *   - from an `updated` model event, where originals are still intact, or
     *   - with $original captured explicitly before saving.
     *
     * If neither holds, the old values are unknowable and are recorded as null
     * rather than as a copy of the new ones -- a log that says "unknown" is
     * honest; a log that says the value was always what it now is, is a lie.
     *
     * @param  array<string, mixed>|null  $original
     */
    public function logChanges(
        string $action,
        Model $model,
        ?Authenticatable $actor = null,
        ?array $original = null,
    ): ?AuditLog {
        $changes = $this->redactor->withoutNoise($model->getChanges());

        if ($changes === []) {
            return null;
        }

        $original ??= array_intersect_key($model->getRawOriginal(), $changes);
        $original = array_intersect_key($original, $changes);

        // Originals already synced: we cannot recover the previous values.
        if ($original == $changes) {
            $original = null;
        }

        return $this->log($action, $model, $original, $changes, $actor);
    }

    public function logCreated(Model $model, ?Authenticatable $actor = null): AuditLog
    {
        return $this->log(
            action: Str::snake(class_basename($model)).'.created',
            auditable: $model,
            newValues: $this->redactor->withoutNoise($model->getAttributes()),
            actor: $actor,
        );
    }

    public function logDeleted(Model $model, ?Authenticatable $actor = null): AuditLog
    {
        return $this->log(
            action: Str::snake(class_basename($model)).'.deleted',
            auditable: $model,
            oldValues: $this->redactor->withoutNoise($model->getAttributes()),
            actor: $actor,
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function prepare(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        return $this->redactor->redact($this->redactor->withoutNoise($values));
    }

    private function resolveActor(): ?Authenticatable
    {
        foreach (['web', 'customer'] as $guard) {
            if (auth()->guard($guard)->check()) {
                return auth()->guard($guard)->user();
            }
        }

        return null;
    }

    private function actorType(?Authenticatable $actor): ActorType
    {
        return match (true) {
            $actor instanceof User => ActorType::User,
            $actor instanceof CustomerPortalUser => ActorType::CustomerPortalUser,
            default => ActorType::System,
        };
    }

    /**
     * Set while a Super Admin is impersonating, so an action is attributed to
     * both identities rather than only the one it appeared to come from.
     */
    private function impersonatorId(): ?int
    {
        if (! request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get('impersonator_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function requestId(): ?string
    {
        $header = request()->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header) ? $header : null;
    }
}
