<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return [
            'required',
            'string',
            Password::min(12)
                ->letters()
                ->numbers()
                // k-anonymity range query against HIBP: only the first five
                // characters of the SHA-1 hash leave the server, never the
                // password itself. Skipped in tests so the suite does not
                // depend on an external service.
                ->when(! app()->runningUnitTests(), fn (Password $rule) => $rule->uncompromised()),
            'confirmed',
        ];
    }
}
