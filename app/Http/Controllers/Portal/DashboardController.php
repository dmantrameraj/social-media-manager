<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Customers\Services\PortalPostQuery;
use App\Domain\Publishing\Enums\PostStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * What the client sees on arrival.
 *
 * Deliberately narrow: what needs my answer, and what is coming up. A client
 * portal that tries to be a second dashboard becomes a support burden for the
 * agency, and every extra panel is another chance to leak something the client
 * was not meant to see.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly PortalPostQuery $posts) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user('customer');

        return view('portal.dashboard', [
            'title' => 'Overview',
            'brands' => $user->customers()->get(),

            'awaiting' => $this->posts->awaitingReview($user)
                ->with('customer')
                ->orderBy('scheduled_at')
                ->limit(10)
                ->get(),

            'awaitingCount' => $this->posts->awaitingReview($user)->count(),

            'upcoming' => $this->posts->for($user)
                ->whereIn('status', [
                    PostStatus::ClientApproved->value,
                    PostStatus::Scheduled->value,
                ])
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '>=', now())
                ->with('customer')
                ->orderBy('scheduled_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
