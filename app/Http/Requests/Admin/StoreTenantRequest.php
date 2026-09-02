<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual agency provisioning by a Super Admin.
 *
 * Note there is no password field, and there must never be one: staff creating
 * an account do not choose a customer's credential. The owner arrives through
 * the password-reset flow instead.
 */
final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->can('platform.tenants.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'owner_name' => ['required', 'string', 'min:2', 'max:120'],

            /*
             | Deliberately NOT unique. An existing user may legitimately own a
             | second agency, and the controller reuses their account rather
             | than refusing or creating a duplicate identity.
             |
             | `rfc` without `dns`: a DNS lookup makes this form fail whenever
             | resolution is slow or unavailable, which on a staff-facing screen
             | trades a real outage for a typo check that the password-reset
             | email already performs.
             */
            'owner_email' => ['required', 'email:rfc', 'max:255'],

            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],

            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record why this agency is being created manually.',
        ];
    }
}
