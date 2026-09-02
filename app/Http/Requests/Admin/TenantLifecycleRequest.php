<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by every Super Admin action that changes an agency's lifecycle.
 *
 * The reason is required at the HTTP boundary as well as in the service. Two
 * checks for one rule is deliberate: the service protects console and future
 * API paths, and this one produces a usable validation error instead of a 500
 * when a human leaves the box empty.
 */
final class TenantLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->can('platform.tenants.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Long enough to be an explanation rather than an initial.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'State why you are making this change. It is recorded in the audit trail.',
            'reason.min' => 'Give a reason someone reading the audit log in six months would understand.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
