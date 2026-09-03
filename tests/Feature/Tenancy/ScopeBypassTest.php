<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | BelongsToTenant::acrossTenants() removes the global scope that keeps one
 | agency's rows away from another's. Its docblock says "an architecture test
 | enforces that" the bypass is confined to allow-listed namespaces.
 |
 | No such test existed. The allow-list in config/tenancy.php was decorative --
 | any file anywhere could call acrossTenants() and nothing would object, which
 | left the isolation guarantee resting on people remembering. Writing this
 | found five namespaces already bypassing without being listed.
 */

/**
 * Namespaces that really call acrossTenants(), comments excluded.
 *
 * Tokenised rather than grepped: a docblock reading "prefer
 * Model::acrossTenants()" is advice, not a bypass, and counting it would grow
 * the allow-list to cover files that call nothing at all.
 *
 * @return list<string>
 */
function namespacesUsingScopeBypass(): array
{
    $found = [];

    foreach (applicationFiles() as $path) {
        $source = (string) file_get_contents($path);

        // The trait defines the scope; it is not a caller.
        if (str_contains($source, 'public function scopeAcrossTenants')) {
            continue;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        $calls = false;
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === 'acrossTenants') {
                $calls = true;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($i = $index + 1; $i < $count; $i++) {
                    if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                        break;
                    }

                    if (is_array($tokens[$i]) && $tokens[$i][0] !== T_WHITESPACE) {
                        $namespace .= $tokens[$i][1];
                    }
                }
            }
        }

        if ($calls) {
            $found[] = $namespace;
        }
    }

    return array_values(array_unique($found));
}

it('confines the tenant scope bypass to allow-listed namespaces', function (): void {
    $allowed = (array) config('tenancy.scope_bypass_namespaces', []);

    $offenders = [];

    foreach (namespacesUsingScopeBypass() as $namespace) {
        $permitted = false;

        foreach ($allowed as $prefix) {
            if ($namespace === $prefix || str_starts_with($namespace, $prefix.'\\')) {
                $permitted = true;
                break;
            }
        }

        if (! $permitted) {
            $offenders[] = $namespace;
        }
    }

    expect($offenders)->toBeEmpty(
        'These namespaces bypass tenant scoping but are not allow-listed in '
        .'config/tenancy.php: '.implode(', ', $offenders)
    );
});

it('reaches media from a worker running under a different tenant', function (): void {
    /*
     | Why the media jobs namespace is on the list, stated as the case that
     | actually bites: a worker processing tenant A's image while the container
     | still holds tenant B's context. Without the bypass the job finds nothing
     | and the image stays in `processing` for ever -- which is the exact bug
     | the job was written to fix.
     */
    [$tenantA] = provisionTenant('Agency A');

    $ownerB = User::factory()->create();
    $tenantB = app(ProvisionTenantService::class)->execute($ownerB, 'Agency B');

    actingForTenant($tenantA);

    $brandA = Customer::factory()->create(['tenant_id' => $tenantA->getKey()]);

    $media = Media::factory()->forCustomer($brandA)->create([
        'status' => MediaStatus::Processing,
        'mime_type' => 'image/png',
    ]);

    actingForTenant($tenantB);

    expect(Media::query()->find($media->getKey()))->toBeNull()
        ->and(Media::query()->acrossTenants()->find($media->getKey()))->not->toBeNull();
});
