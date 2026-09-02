<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Domain\Identity\Models\CustomerPortalUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A client's answer on a post: approve, reject, or request changes.
 *
 * authorize() only establishes that the caller is a portal user at all. Whether
 * they may decide *this* post is a question about brand assignment and workflow
 * stage, answered by PortalPostQuery and PostStatusMachine -- a form request
 * that has not resolved the post cannot answer it, and pretending otherwise
 * would put a half-check somewhere that looks authoritative.
 */
final class PortalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') instanceof CustomerPortalUser;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            /*
             | Optional on approval -- "yes" needs no explanation -- but the
             | view asks for one on reject and request-changes, because a
             | rejection with no reason turns into an email thread the agency
             | has to chase.
             */
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
