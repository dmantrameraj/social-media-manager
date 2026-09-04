<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Analytics\Models\ReportShare;

/**
 * Turns a share token into the report it names.
 *
 * Lives beside ResolveDomainService and for the same reason: an anonymous
 * request arrives before any tenant is known, and working out which one it
 * belongs to IS the job. App\Domain\Platform is already on
 * config('tenancy.scope_bypass_namespaces') for exactly this, so the bypass
 * stays where its reasoning is instead of being granted to every controller.
 */
final class ResolveReportShareService
{
    /**
     * The share a token names, or null.
     *
     * Looked up by HASH -- the plaintext is never stored, so a database read
     * does not yield a working link. Expired and revoked shares resolve to
     * null here rather than being filtered later, so a caller cannot forget.
     */
    public function forToken(string $token): ?ReportShare
    {
        $share = ReportShare::query()
            ->acrossTenants()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $share !== null && $share->isViewable() ? $share : null;
    }
}
