<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class StartImpersonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->hasTwoFactorEnabled()
            && $user->can('platform.impersonate');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            /*
             | The longest minimum on the admin surface. Entering a customer's
             | account is the single most invasive thing staff can do, and
             | "support" is not an account of why it was necessary.
             */
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Impersonation requires a reason. It is recorded against your name.',
            'reason.min' => 'Describe what you are investigating, not just that you are investigating.',
        ];
    }
}
