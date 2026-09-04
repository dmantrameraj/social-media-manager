<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\Contracts\DnsLookup;
use App\Domain\Platform\Models\Domain;

/**
 * Proves an agency controls a hostname before it resolves to anything.
 *
 * The check is the whole security boundary for custom domains. Without it,
 * anybody could point DNS at this application, claim a hostname, and be served
 * another agency's client portal -- so a domain resolves only after the token
 * we generated appears in DNS for that exact name.
 */
final class VerifyDomainService
{
    public function __construct(
        private readonly DnsLookup $dns,
        private readonly AuditLogger $audit,
    ) {}

    public function verify(Domain $domain): bool
    {
        if ($domain->verification_token === null) {
            return false;
        }

        /*
         | Looked up on the domain itself rather than a _verification
         | subdomain. Both are common; this one is simpler to explain to a
         | client's IT department, which is usually who has to add the record.
         */
        $records = $this->dns->txtRecords($domain->hostname);

        /*
         | Exact match, not a substring. A TXT record CONTAINING our token is
         | not the same as one equal to it: a shared record with several values
         | concatenated could otherwise be made to satisfy a token that was
         | never published for this domain.
         */
        $found = in_array($domain->verification_token, array_map('trim', $records), true);

        if (! $found) {
            return false;
        }

        $domain->forceFill([
            'verified_at' => now(),
            /*
             | The token is KEPT. Re-verification happens -- DNS changes, a
             | domain is moved -- and regenerating on every check would mean
             | the record an agency published stops matching for reasons they
             | did nothing to cause.
             */
        ])->save();

        $this->audit->log(
            'domain.verified',
            $domain,
            newValues: ['hostname' => $domain->hostname],
            tenantId: $domain->tenant_id,
        );

        return true;
    }
}
