<?php

declare(strict_types=1);

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;

final class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
            // Ownership of each id is verified in the controller against the
            // chosen brand; "exists" alone would accept another brand's
            // account.
            'accounts' => ['array'],
            'accounts.*' => ['integer'],

            /*
             | Same rule as accounts: ownership of each id is verified in the
             | controller against the CHOSEN BRAND. `exists:media,id` would
             | happily accept another brand's -- or another tenant's -- file,
             | because it only asks whether the row exists.
             |
             | Capped at 10 because every platform caps a carousel below that
             | and an unbounded array is a cheap way to make one request do a
             | lot of work.
             */
            'media' => ['array', 'max:10'],
            'media.*' => ['integer'],
            // Minimum lead time keeps a new post out of the sweeper's current
            // pass, which is already scanning for work due now.
            'scheduled_at' => ['nullable', 'date', 'after:'.now()->addSeconds(
                (int) config('publishing.min_lead_seconds', 60)
            )->toDateTimeString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'Choose a time at least a minute from now.',
            'body.required' => 'A post needs some content.',
        ];
    }
}
