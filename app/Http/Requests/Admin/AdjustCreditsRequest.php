<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AdjustCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->can('platform.credits.adjust');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /*
             | Signed: negative removes credits, which is how a mistaken grant
             | gets corrected. Bounded in both directions so a slipped digit
             | cannot hand out a fortune -- a larger correction is a deliberate
             | second action, not a typo.
             */
            'delta' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'],

            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delta.not_in' => 'An adjustment of zero would do nothing.',
            'delta.between' => 'Adjustments are capped at one million credits per action.',
            'reason.required' => 'Record why this balance is being corrected.',
        ];
    }
}
