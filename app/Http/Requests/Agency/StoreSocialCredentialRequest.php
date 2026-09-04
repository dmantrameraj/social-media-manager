<?php

declare(strict_types=1);

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An agency's own developer app.
 *
 * The values here are the most sensitive thing this application accepts from a
 * form, so two rules apply that do not apply elsewhere:
 *
 *   - No `nullable` on a create. A blank secret stored as an empty string
 *     would look like a configured app and fail at the moment somebody tries
 *     to connect an account, which is the worst moment to discover it.
 *   - Nothing is echoed back. The usual `old()` repopulation is deliberately
 *     not used for these fields on the form, because a validation failure
 *     would otherwise put the secret back into the HTML.
 */
final class StoreSocialCredentialRequest extends FormRequest
{
    /**
     * Authorised HERE, not in the controller.
     *
     * A FormRequest validates before the controller body runs, so a permission
     * check inside the action would answer an unauthorised user with a
     * validation error -- telling them which fields the endpoint wants, and
     * confirming it exists, before deciding they may not use it. authorize()
     * runs first.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('social_credentials.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only networks the registry actually knows. A credential for a
            // provider that does not exist can never be used and would sit
            // there looking configured.
            'provider_key' => ['required', 'string', 'max:40', Rule::in(
                array_keys((array) config('social.providers', []))
            )],

            /*
             | The label is what the screen shows, and it is the ONLY thing
             | about the app a person will ever see again -- so it has to
             | distinguish two apps for the same network. The unique index is
             | (tenant_id, provider_key, label).
             */
            'label' => ['required', 'string', 'max:120'],

            'client_id' => ['required', 'string', 'max:500'],
            'client_secret' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_key.in' => 'That network is not one this platform supports.',
            'label.required' => 'Give the app a name so you can tell it apart later.',
        ];
    }
}
