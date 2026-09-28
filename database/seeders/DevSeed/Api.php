<?php

namespace Database\Seeders\DevSeed;

use App\Models\Employee;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * In-process client for the application's own JSON API. Development seeding goes through the same routes, form requests,
 * permissions and services as the browser, signed in as the employee who would perform the action.
 */
final class Api
{
    private int $requests = 0;

    public function __construct(private readonly HttpKernel $kernel) {}

    /**
     * Send a request as $actor and return the decoded body; any non-2xx response is an error.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function call(?Employee $actor, string $method, string $uri, array $data = [], array $files = [], array $headers = []): array
    {
        [$status, $body] = $this->attempt($actor, $method, $uri, $data, $files, $headers);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('%s %s as %s failed (%d): %s', $method, $uri, $actor?->full_name ?? 'guest', $status, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return $body;
    }

    /**
     * Send a request and return [status, decoded body] without failing on an error status.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function attempt(?Employee $actor, string $method, string $uri, array $data = [], array $files = [], array $headers = []): array
    {
        $auth = app('auth');
        $auth->forgetGuards();
        if ($actor !== null) {
            $auth->guard('sanctum')->setUser($actor->fresh());
            $auth->shouldUse('sanctum');
        }

        $path = str_starts_with($uri, '/') ? $uri : '/api/v1/'.$uri;
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'DevelopmentTestDataSeeder'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($method === 'GET') {
            $request = Request::create($path, 'GET', $data, [], [], $server);
        } elseif ($files !== []) {
            $request = Request::create($path, $method, $data, [], $files, $server);
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $request = Request::create($path, $method, [], [], [], $server, json_encode($data, JSON_THROW_ON_ERROR));
        }

        $response = $this->kernel->handle($request);
        $this->requests++;
        $auth->forgetGuards();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    public function requests(): int
    {
        return $this->requests;
    }
}
