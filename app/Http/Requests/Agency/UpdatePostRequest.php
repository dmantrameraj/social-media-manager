<?php

declare(strict_types=1);

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same shape as StorePostRequest, minus the brand.
 *
 * A post does not change brand. Moving one between clients would carry its
 * approval history, its comments and its targets across a boundary that every
 * other part of this application treats as absolute -- and the right way to
 * put the same words in front of another client is to write another post.
 */
final class UpdatePostRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],

            // Ownership of each id is verified in the controller against the
            // post's brand. "exists" alone would accept another brand's.
            'accounts' => ['array'],
            'accounts.*' => ['integer'],

            'media' => ['array', 'max:10'],
            'media.*' => ['integer'],

            /*
             | No minimum lead time here, unlike the composer.
             |
             | An editable post is a draft: it is not in the queue and nothing
             | is going to pick it up, so a time an hour from now is a plan,
             | not a race with the sweeper. The floor is applied where it
             | matters -- at the moment somebody schedules it.
             */
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A post needs some content.',
        ];
    }
}
