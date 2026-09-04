<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Services\ImportPostsService;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/*
 | CSV bulk import.
 |
 | The roadmap criterion is "CSV import handles partial success with per-row
 | reporting", and the second half is the part worth testing. An import that
 | fails whole is a spreadsheet somebody has to bisect by hand.
 |
 | posts.bulk_import had been in the permission catalogue since Step 5
 | governing nothing.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->travelTo(Carbon::parse('2026-03-01 12:00:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'timezone' => 'Asia/Kolkata',
    ]);

    $this->importer = app(ImportPostsService::class);
});

/** A CSV on disk, returned as a path. */
function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

function importCsv(string $contents, ?User $as = null)
{
    return test()->importer->execute(csvFile($contents), $as ?? test()->owner);
}

it('imports a row as a draft', function (): void {
    $report = importCsv(<<<'CSV'
    brand,title,body
    Roast House,Autumn blend,The autumn blend lands on Friday.
    CSV);

    expect($report->created())->toBe(1)
        ->and($report->failed())->toBe(0);

    $post = Post::query()->sole();

    /*
     | A draft, always. A CSV that could schedule would be a way to put content
     | past the approval gate and into a client's feed by uploading a file.
     */
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->title)->toBe('Autumn blend')
        ->and($post->source)->toBe('import')
        ->and($post->customer_id)->toBe($this->brand->getKey());
});

it('keeps the good rows when one is bad', function (): void {
    /*
     | The whole point. Row 3 is unusable; rows 2 and 4 are not, and throwing
     | them away would mean editing the file and re-uploading everything.
     */
    $report = importCsv(<<<'CSV'
    brand,title,body
    Roast House,First,Something to say.
    Roast House,Second,
    Roast House,Third,Something else.
    CSV);

    expect($report->created())->toBe(2)
        ->and($report->failed())->toBe(1)
        ->and(Post::query()->count())->toBe(2);

    $failure = $report->failures()[0];

    // The line number counts the header, because that is what the user sees
    // when they open the file to fix it.
    expect($failure->line)->toBe(3)
        ->and($failure->message)->toContain('needs some content');
});

it('names the reason for each skipped row', function (): void {
    $report = importCsv(<<<'CSV'
    brand,title,body,scheduled_at
    Roast House,Fine,Good content.,2026-04-01 09:00
    Nowhere Ltd,Wrong brand,Good content.,2026-04-01 09:00
    Roast House,Bad date,Good content.,not a date at all
    Roast House,Past,Good content.,2020-01-01 09:00
    CSV);

    $messages = collect($report->failures())->pluck('message', 'line');

    expect($report->created())->toBe(1)
        ->and($messages[3])->toContain('No brand of yours')
        ->and($messages[4])->toContain('not a date')
        ->and($messages[5])->toContain('already passed');
});

it('reads a scheduled time in the brand timezone', function (): void {
    importCsv(<<<'CSV'
    brand,body,scheduled_at
    Roast House,Morning post,2026-04-15 09:00
    CSV);

    $post = Post::query()->sole();

    // A spreadsheet carries no timezone, and whoever typed 09:00 meant nine in
    // the morning where the client is. Kolkata is +05:30.
    expect($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-04-15 03:30')
        ->and($post->timezone)->toBe('Asia/Kolkata');
});

it('attaches the named accounts', function (): void {
    $account = SocialAccount::factory()->forCustomer($this->brand)
        ->create(['name' => 'Roast House Page', 'username' => 'roasthouse']);

    importCsv(<<<'CSV'
    brand,body,accounts
    Roast House,With a destination,roasthouse
    CSV);

    expect(Post::query()->sole()->targets()->sole()->social_account_id)
        ->toBe($account->getKey());
});

it('skips a row naming an account that is not on that brand', function (): void {
    $other = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Other Brand',
    ]);

    SocialAccount::factory()->forCustomer($other)->create(['username' => 'elsewhere']);

    $report = importCsv(<<<'CSV'
    brand,body,accounts
    Roast House,Wrong destination,elsewhere
    CSV);

    // Publishing a client's post to another client's feed is the worst thing
    // this feature could do, so an unresolvable name is a refusal.
    expect($report->created())->toBe(0)
        ->and($report->failures()[0]->message)->toContain('not an account connected');
});

it('refuses to guess between two accounts with the same name', function (): void {
    SocialAccount::factory()->forCustomer($this->brand)->create(['name' => 'Roast House']);
    SocialAccount::factory()->forCustomer($this->brand)->create(['name' => 'Roast House']);

    $report = importCsv(<<<'CSV'
    brand,body,accounts
    Roast House,Ambiguous,Roast House
    CSV);

    expect($report->failures()[0]->message)->toContain('more than one account');
});

it('ignores an account that cannot publish', function (): void {
    SocialAccount::factory()->forCustomer($this->brand)->create([
        'username' => 'gone',
        'status' => AccountStatus::Disconnected->value,
    ]);

    $report = importCsv(<<<'CSV'
    brand,body,accounts
    Roast House,To a dead account,gone
    CSV);

    // Same scope the composer uses. A CSV should not be the one path that gets
    // to target a disconnected account.
    expect($report->created())->toBe(0);
});

it('will not import into another agency brand', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    Customer::factory()->create(['tenant_id' => $rival->getKey(), 'name' => 'Rival Brand']);
    actingForTenant($this->tenant);

    $report = importCsv(<<<'CSV'
    brand,body
    Rival Brand,Not yours
    CSV);

    expect($report->created())->toBe(0)
        // The same message a nonexistent brand gets. Telling them apart would
        // make this form a way to enumerate other agencies' clients.
        ->and($report->failures()[0]->message)->toContain('No brand of yours');
});

it('will not import into a brand this user is not assigned to', function (): void {
    $creator = memberWithRole($this->tenant, 'Content Creator');

    // A Content Creator sees only their assigned brands, and none are assigned.
    $report = importCsv(<<<'CSV'
    brand,body
    Roast House,Not mine
    CSV, $creator);

    expect($report->created())->toBe(0);
});

it('rejects a file with no body column', function (): void {
    $report = importCsv(<<<'CSV'
    brand,title
    Roast House,Nothing to post
    CSV);

    // A file-level problem, so nothing is imported: a file whose shape we
    // misread would import the wrong columns silently.
    expect($report->fatal)->toContain('body')
        ->and(Post::query()->count())->toBe(0);
});

it('reads a semicolon separated export', function (): void {
    // Excel in several locales writes this and still calls it CSV.
    $report = importCsv("brand;title;body\nRoast House;Semicolons;It still works.\n");

    expect($report->created())->toBe(1)
        ->and(Post::query()->sole()->title)->toBe('Semicolons');
});

it('reads back a file it wrote itself', function (): void {
    // Our own exports carry a BOM so Excel reads them as UTF-8. A first column
    // called "\u{FEFF}brand" would match nothing.
    $report = importCsv("\u{FEFF}brand,body\nRoast House,Round trip\n");

    expect($report->created())->toBe(1);
});

it('stops at the row limit', function (): void {
    config()->set('publishing.import.max_rows', 3);

    $rows = collect(range(1, 6))
        ->map(fn (int $i): string => "Roast House,Post {$i}")
        ->implode("\n");

    $report = importCsv("brand,body\n".$rows);

    expect($report->created())->toBe(3)
        ->and($report->failures()[0]->message)->toContain('row limit');
});

it('ignores blank lines', function (): void {
    $report = importCsv("brand,body\nRoast House,One\n\n\nRoast House,Two\n");

    expect($report->created())->toBe(2)
        ->and($report->failed())->toBe(0);
});

it('records the import', function (): void {
    importCsv("brand,body\nRoast House,Audited\n");

    expect(AuditLog::query()->where('action', 'posts.imported')->exists())->toBeTrue();
});

// ------------------------------------------------------------------ the screen

it('needs the import permission', function (): void {
    asAgencyUser(memberWithRole($this->tenant, 'Designer'))
        ->get(route('agency.posts.import'))
        ->assertForbidden();
});

it('shows a manager the import screen', function (): void {
    asAgencyUser(memberWithRole($this->tenant, 'Manager'))
        ->get(route('agency.posts.import'))
        ->assertOk()
        ->assertSee('Import posts');
});

it('reports each row on the screen', function (): void {
    $csv = UploadedFile::fake()->createWithContent('posts.csv', <<<'CSV'
    brand,title,body
    Roast House,Good,Something to say.
    Roast House,Bad,
    CSV);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.import.store'), ['file' => $csv])
        ->assertOk()
        ->assertSee('1 rows were skipped')
        ->assertSee('needs some content');
});

it('offers a template that it can read back', function (): void {
    $response = asAgencyUser($this->owner)
        ->get(route('agency.posts.import.template'))
        ->assertOk();

    // The template and the parser agree because they are tested together: a
    // renamed column breaks this rather than leaving stale documentation.
    $report = importCsv($response->streamedContent());

    expect($report->fatal)->toBeNull()
        // The sample row names a brand this workspace does have.
        ->and($report->created())->toBe(1);
});
