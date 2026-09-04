<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\DTO\ImportReport;
use App\Domain\Publishing\DTO\ImportRow;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bulk import of posts from a CSV.
 *
 * The point of the feature is a month of content in one go; the point of THIS
 * implementation is that row 14 being wrong does not throw away rows 1 to 13.
 * Each row is its own transaction and its own verdict, and the caller is handed
 * every verdict rather than a count.
 *
 * Two rules that are not negotiable, both of which a simpler import would break:
 *
 *   - Everything lands as a DRAFT. A CSV that could schedule would be a way to
 *     put content past the approval gate and into a client's feed by uploading
 *     a file, and `approval_required` would mean nothing.
 *   - Brands and accounts are resolved against THIS user's access, not merely
 *     against the tenant. A name in a spreadsheet is not authorisation.
 */
final class ImportPostsService
{
    /** Columns we read. Anything else in the file is ignored, not an error. */
    private const COLUMNS = ['brand', 'title', 'body', 'scheduled_at', 'accounts'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $path  a readable CSV; never stored, only read
     */
    public function execute(string $path, User $user): ImportReport
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ImportReport::fatal('That file could not be read.');
        }

        try {
            return $this->read($handle, $user);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function read($handle, User $user): ImportReport
    {
        $header = fgetcsv($handle, escape: '');

        if ($header === false || $header === [null]) {
            return ImportReport::fatal('That file is empty.');
        }

        /*
         | Excel in several locales writes a semicolon-separated file and still
         | calls it CSV. Sniffing the header beats telling a user their export
         | is wrong, and it is unambiguous: a header row with no comma and a
         | semicolon in it was not comma-separated.
         */
        if (count($header) === 1 && str_contains((string) $header[0], ';')) {
            $header = str_getcsv((string) $header[0], ';', escape: '');
            $semicolons = true;
        } else {
            $semicolons = false;
        }

        $map = $this->mapColumns($header);

        foreach (['brand', 'body'] as $required) {
            if (! array_key_exists($required, $map)) {
                return ImportReport::fatal(
                    "That file has no \"{$required}\" column. Required columns are brand and body."
                );
            }
        }

        $brands = $this->visibleBrands($user)->keyBy(
            fn (Customer $brand): string => mb_strtolower(trim($brand->name))
        );

        $max = (int) config('publishing.import.max_rows', 500);
        $rows = [];
        $line = 1; // the header
        $created = 0;

        while (($record = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($semicolons && count($record) === 1) {
                $record = str_getcsv((string) $record[0], ';', escape: '');
            }

            // fgetcsv yields [null] for a blank line. Skipping silently is
            // right: a trailing newline is not a row somebody wrote.
            if ($record === [null] || $this->isBlank($record)) {
                continue;
            }

            if (count($rows) >= $max) {
                $rows[] = ImportRow::skipped($line, "Stopped at the {$max} row limit.");
                break;
            }

            $rows[] = $row = $this->importRow($record, $map, $brands, $user, $line);

            if ($row->created) {
                $created++;
            }
        }

        if ($created > 0) {
            $this->audit->log(
                action: 'posts.imported',
                auditable: null,
                newValues: ['created' => $created, 'rows' => count($rows)],
                actor: $user,
            );
        }

        return new ImportReport($rows);
    }

    /**
     * @param  array<int, string|null>  $record
     * @param  array<string, int>  $map
     * @param  Collection<string, Customer>  $brands
     */
    private function importRow(
        array $record,
        array $map,
        Collection $brands,
        User $user,
        int $line,
    ): ImportRow {
        $value = function (string $column) use ($record, $map): string {
            $index = $map[$column] ?? null;

            return $index === null ? '' : trim((string) ($record[$index] ?? ''));
        };

        $title = $value('title') ?: null;
        $brandName = $value('brand');
        $body = $value('body');

        if ($body === '') {
            return ImportRow::skipped($line, 'No body. A post needs some content.', $title);
        }

        if (mb_strlen($body) > 20000) {
            return ImportRow::skipped($line, 'The body is longer than 20,000 characters.', $title);
        }

        if ($title !== null && mb_strlen($title) > 190) {
            return ImportRow::skipped($line, 'The title is longer than 190 characters.', $title);
        }

        $brand = $brands->get(mb_strtolower($brandName));

        if ($brand === null) {
            /*
             | The same message whether the brand does not exist or merely is
             | not theirs. Telling the second apart from the first would turn
             | this form into a way to enumerate an agency's client list.
             */
            return ImportRow::skipped(
                $line,
                $brandName === ''
                    ? 'No brand named.'
                    : "No brand of yours is called \"{$brandName}\".",
                $title,
            );
        }

        $when = null;

        if ($value('scheduled_at') !== '') {
            try {
                // Read in the BRAND's zone. A spreadsheet has no timezone, and
                // whoever typed 09:00 meant nine in the morning where the
                // client is.
                $when = Carbon::parse($value('scheduled_at'), $brand->effectiveTimezone())->utc();
            } catch (Throwable) {
                return ImportRow::skipped($line, 'That is not a date we can read.', $title);
            }

            if ($when->isPast()) {
                return ImportRow::skipped($line, 'That time has already passed.', $title);
            }
        }

        $accounts = $this->resolveAccounts($value('accounts'), $brand);

        if (is_string($accounts)) {
            return ImportRow::skipped($line, $accounts, $title);
        }

        try {
            $post = DB::transaction(fn (): Post => $this->create(
                $brand, $user, $title, $body, $when, $accounts,
            ));
        } catch (Throwable $e) {
            report($e);

            // The row is reported, the import continues. A file that dies on
            // row 3 of 40 is worse than one that says which rows it missed.
            return ImportRow::skipped($line, 'That row could not be saved.', $title);
        }

        return ImportRow::ok($line, (int) $post->getKey(), $title);
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    private function create(
        Customer $brand,
        User $user,
        ?string $title,
        string $body,
        ?Carbon $when,
        Collection $accounts,
    ): Post {
        $post = new Post;
        $post->tenant_id = $brand->tenant_id;
        $post->customer_id = $brand->getKey();
        $post->created_by_user_id = $user->getKey();
        $post->title = $title;
        $post->body = $body;
        // No media, so no video or image to derive. Media is deliberately not
        // importable: matching a filename to a library item is a guess, and a
        // guess here posts the wrong picture to a client's feed.
        $post->content_type = 'text';
        $post->status = PostStatus::Draft;
        $post->source = 'import';
        $post->approval_required = $brand->requiresClientApproval();
        $post->timezone = $brand->effectiveTimezone();
        $post->scheduled_at = $when;
        $post->save();

        foreach ($accounts as $account) {
            $target = new PostTarget;
            $target->tenant_id = $post->tenant_id;
            $target->post_id = $post->getKey();
            $target->social_account_id = $account->getKey();
            $target->provider_key = $account->provider_key;
            $target->scheduled_at = $when;
            $target->max_attempts = (int) config('publishing.max_attempts', 3);
            $target->idempotency_key = hash('sha256', $post->getKey().':'.$account->getKey().':'.Str::ulid());
            $target->save();
        }

        return $post;
    }

    /**
     * Accounts named in a cell, resolved within the brand.
     *
     * Returns a string when the row should be skipped, so the caller reports
     * the reason rather than importing a post aimed at nothing.
     *
     * @return Collection<int, SocialAccount>|string
     */
    private function resolveAccounts(string $cell, Customer $brand)
    {
        if ($cell === '') {
            return collect();
        }

        // publishable(), the same scope the composer uses: a disconnected or
        // unhealthy account is not a destination, and a CSV should not be the
        // one path that gets to ignore that.
        $available = SocialAccount::query()
            ->publishable()
            ->where('customer_id', $brand->getKey())
            ->get();

        $resolved = collect();

        foreach (array_filter(array_map('trim', explode(',', $cell))) as $name) {
            $matches = $available->filter(fn (SocialAccount $account): bool => in_array(
                mb_strtolower($name),
                array_filter([
                    mb_strtolower((string) $account->username),
                    mb_strtolower((string) $account->name),
                ]),
                true,
            ));

            if ($matches->isEmpty()) {
                return "\"{$name}\" is not an account connected to {$brand->name}.";
            }

            if ($matches->count() > 1) {
                // Guessing which of two accounts they meant is how a post ends
                // up on the wrong feed.
                return "\"{$name}\" matches more than one account on {$brand->name}.";
            }

            $resolved->push($matches->first());
        }

        return $resolved->unique(fn (SocialAccount $account) => $account->getKey())->values();
    }

    /**
     * Brands this user may actually post to.
     *
     * @return Collection<int, Customer>
     */
    private function visibleBrands(User $user): Collection
    {
        $query = Customer::query()->active();

        if (! $user->can('customers.view_all')) {
            $query->whereIn('id', $user->assignedCustomerIds());
        }

        return $query->get();
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            // The BOM we write on export comes back on re-upload, and a first
            // column called "\u{FEFF}brand" matches nothing.
            $name = mb_strtolower(trim(str_replace("\u{FEFF}", '', (string) $name)));
            $name = str_replace([' ', '-'], '_', $name);

            if (in_array($name, self::COLUMNS, true) && ! array_key_exists($name, $map)) {
                $map[$name] = $index;
            }
        }

        return $map;
    }

    /** @param array<int, string|null> $record */
    private function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
