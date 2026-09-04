<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Publishing\Services\ImportPostsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import, closing `posts.bulk_import`.
 *
 * The permission has been in the catalogue since Step 5 governing nothing,
 * which is the shape this repository keeps producing: a right that exists, is
 * assigned to roles, and grants access to no screen.
 */
final class PostImportController
{
    public function create(Request $request): View
    {
        $request->user()->can('posts.bulk_import') || abort(403);

        return view('agency.posts.import', [
            'title' => 'Import posts',
            'report' => null,
            'maxRows' => (int) config('publishing.import.max_rows', 500),
        ]);
    }

    public function store(Request $request, ImportPostsService $importer): View
    {
        $request->user()->can('posts.bulk_import') || abort(403);

        $request->validate([
            /*
             | Extension, not mime. Every spreadsheet program on earth labels
             | CSV differently -- text/csv, text/plain, application/vnd.ms-excel
             | -- and rejecting a valid file because Excel called it something
             | unexpected is the kind of validation that teaches people the
             | feature is broken. The content is parsed defensively regardless.
             */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.max' => 'That file is larger than 5 MB.',
            'file.mimes' => 'Upload a .csv file.',
        ]);

        /*
         | Read from the temporary upload and never stored. A CSV of a client's
         | unpublished content is exactly the sort of thing that accumulates in
         | a storage directory for years because nobody decided it should not.
         */
        $report = $importer->execute(
            $request->file('file')->getRealPath(),
            $request->user(),
        );

        return view('agency.posts.import', [
            'title' => 'Import posts',
            'report' => $report,
            'maxRows' => (int) config('publishing.import.max_rows', 500),
        ]);
    }

    /**
     * A template with the headers and one filled row.
     *
     * Cheaper than documentation, and it is the same file the parser reads --
     * so a column rename that broke the template would break this too, rather
     * than leaving a document that quietly describes an older version.
     */
    public function template(Request $request): StreamedResponse
    {
        $request->user()->can('posts.bulk_import') || abort(403);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');

            // The BOM, so Excel reads a non-ASCII brand name as UTF-8 rather
            // than as the system codepage.
            fwrite($out, "\u{FEFF}");

            fputcsv($out, ['brand', 'title', 'body', 'scheduled_at', 'accounts'], escape: '');
            fputcsv($out, [
                'Roast House',
                'Autumn blend',
                'The autumn blend lands on Friday.',
                now()->addWeek()->format('Y-m-d H:i'),
                '',
            ], escape: '');

            fclose($out);
        }, 'post-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
