<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Contracts\DnsLookup;

/**
 * Reads TXT records from the resolver the host is configured to use.
 *
 * Deliberately thin. Everything interesting about verification lives in
 * VerifyDomainService, which is testable because this is behind an interface.
 */
final class SystemDnsLookup implements DnsLookup
{
    /** @return list<string> */
    public function txtRecords(string $hostname): array
    {
        /*
         | Suppressed and defaulted: dns_get_record emits a warning and returns
         | false for a name that does not resolve, which is the ordinary case
         | when somebody adds a domain before touching DNS. That is an answer,
         | not an error.
         */
        $records = @dns_get_record($hostname, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            /*
             | `txt` holds the joined value; `entries` holds the segments a
             | long record was split into. Both are read because a token near
             | the 255-character limit can arrive either way depending on the
             | resolver.
             */
            if (isset($record['txt'])) {
                $values[] = (string) $record['txt'];
            }

            foreach ((array) ($record['entries'] ?? []) as $entry) {
                $values[] = (string) $entry;
            }
        }

        return array_values(array_unique($values));
    }
}
