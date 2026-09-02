<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEntitlementOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->can('platform.entitlements.override');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /*
             | Rule::in over the catalogue, not a free string. An override on a
             | key nothing reads is invisible: it looks applied, changes
             | nothing, and surfaces as a support ticket months later.
             */
            'key' => ['required', 'string', Rule::in(array_keys((array) config('entitlements.keys', [])))],

            'value_type' => ['required', Rule::enum(EntitlementType::class)],

            /*
             | Required unless the type is unlimited, where a number would be
             | meaningless. Nullable-with-required_unless rather than plain
             | nullable, so a blank Limit override cannot be stored as zero and
             | quietly lock the tenant out of the feature.
             */
            'value' => ['nullable', 'integer', 'min:0', 'max:2147483647',
                'required_unless:value_type,'.EntitlementType::Unlimited->value],

            'expires_at' => ['nullable', 'date', 'after:now'],

            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.in' => 'That is not a known entitlement key.',
            'value.required_unless' => 'Give a value, or set the type to unlimited.',
            'expires_at.after' => 'An override that has already expired would do nothing.',
            'reason.required' => 'Record why this agency is getting a different limit.',
        ];
    }
}
