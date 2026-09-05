<?php

declare(strict_types=1);

namespace App\Domain\Social\Providers\Meta;

use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One HTTP client for every Meta surface.
 *
 * Facebook Pages and Instagram Business are the same Graph API with different
 * node types, so the parts that are easy to get subtly wrong -- the version in
 * the path, the error taxonomy, the timeout -- are written once here rather
 * than twice in two adapters that would drift.
 *
 * VERIFIED AGAINST developers.facebook.com, 2026-09-05, Graph API v25.0.
 * Every endpoint, parameter and error code below was read from Meta's own
 * documentation rather than recalled. §64 of the brief forbids the alternative,
 * and for a good reason: a wrong field name here does not throw, it silently
 * publishes the wrong thing or records a metric that was never measured.
 */
final class MetaGraphClient
{
    public function __construct(private readonly string $version) {}

    public static function make(): self
    {
        return new self((string) config('social.meta.graph_version', 'v25.0'));
    }

    public function baseUrl(): string
    {
        return "https://graph.facebook.com/{$this->version}";
    }

    /** The authorization dialog lives on www, not on graph. */
    public function dialogUrl(): string
    {
        return "https://www.facebook.com/{$this->version}/dialog/oauth";
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        try {
            $response = Http::asForm()
                ->timeout((int) config('social.meta.timeout', 20))
                ->{$method}($url, $data);
        } catch (ConnectionException $e) {
            /*
             | A connection that never landed is retryable and must not consume
             | an attempt: nothing was published, so trying again cannot
             | duplicate anything.
             */
            throw new ProviderException(
                ProviderErrorClass::Network,
                'Could not reach the network.',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new ProviderException(
                ProviderErrorClass::Unknown,
                'The request failed before a reply arrived.',
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw $this->exceptionFor($response);
        }

        return (array) $response->json();
    }

    /**
     * Map a Graph error onto our own taxonomy.
     *
     * The engine must never see a Meta subcode: it decides retry, attempt
     * consumption and reconnect from ProviderErrorClass alone, and every
     * provider maps into it. Codes below are from Meta's error-handling
     * reference, read 2026-09-05.
     */
    public function exceptionFor(Response $response): ProviderException
    {
        /** @var array<string, mixed> $error */
        $error = (array) ($response->json('error') ?? []);

        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['message'] ?? 'The network refused the request.');

        $class = $this->classify($code, $subcode, $response->status());

        return new ProviderException(
            $class,
            /*
             | error_user_msg is Meta's own wording for the END USER and is the
             | one string here safe to show somebody. `message` is for
             | developers and frequently names internal fields.
             */
            (string) ($error['error_user_msg'] ?? $message),
            providerCode: $subcode !== 0 ? "{$code}/{$subcode}" : (string) $code,
            httpStatus: $response->status(),
            context: [
                'code' => $code,
                'subcode' => $subcode,
                // fbtrace_id is what Meta's own support asks for first.
                'fbtrace_id' => $error['fbtrace_id'] ?? null,
            ],
        );
    }

    private function classify(int $code, int $subcode, int $status): ProviderErrorClass
    {
        return match (true) {
            // 4 API Too Many Calls, 17 API User Too Many Calls,
            // 341 Application limit reached.
            in_array($code, [4, 17, 32, 341, 613], true) => ProviderErrorClass::RateLimit,

            /*
             | 190 with a re-authentication subcode: the user must go back to
             | Facebook, so this is not something a retry can fix. 458 not
             | logged in, 459 checkpointed, 460 password changed, 463 expired,
             | 464 unconfirmed, 467 invalid.
             */
            $code === 190 => ProviderErrorClass::AuthExpired,

            // 10 API Permission Denied, and the 200-299 permission block.
            $code === 10 || ($code >= 200 && $code <= 299) => ProviderErrorClass::Permission,

            // 506 Duplicate post. Meta refuses identical content, and our
            // engine treats that as already-done rather than as a failure.
            $code === 506 => ProviderErrorClass::Duplicate,

            $code === 100 => ProviderErrorClass::Validation,

            $status >= 500 => ProviderErrorClass::ServerError,

            default => ProviderErrorClass::Unknown,
        };
    }

    /**
     * Whether an auth failure needs the user, or merely a fresh token.
     *
     * Kept next to the mapping it belongs to: subcodes 458-464 mean the person
     * has to return to Facebook, and telling them to "try again" instead is
     * how an account sits broken for a fortnight.
     */
    public function requiresUserAction(int $subcode): bool
    {
        return in_array($subcode, [458, 459, 460, 464], true);
    }
}
