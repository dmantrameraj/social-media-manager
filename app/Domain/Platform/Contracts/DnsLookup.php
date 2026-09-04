<?php

declare(strict_types=1);

namespace App\Domain\Platform\Contracts;

/**
 * Reads TXT records for a hostname.
 *
 * An interface so verification is testable without the network. A test that
 * depends on live DNS is a test that fails on an aeroplane and passes for
 * reasons nobody can reproduce.
 *
 * @return list<string>
 */
interface DnsLookup
{
    /** @return list<string> */
    public function txtRecords(string $hostname): array;
}
