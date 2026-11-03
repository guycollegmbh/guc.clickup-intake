<?php

namespace Tests\Support;

use App\ClickUp\ClickUpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ClickUp fixtures without real data, and a router for Http::fake() analog FakeGraph.
 *
 * Routes are "METHOD glob" against the decoded path and query below /api/v2/, e.g.
 * "POST list/901/task". The first match wins. Values are responses, Http::sequence() or
 * closures receiving the request. Requests to other hosts are left to further fakes.
 *
 * Workspace TEAM_ID with space "Kunden" (folder "Projekte" with list 901, folderless list 902)
 * and the members 501 and 502.
 */
class FakeClickUp
{
    public const TEAM_ID = '9001';

    public const OTHER_TEAM_ID = '9002';

    public const MEMBER_ID = 501;

    public const OTHER_MEMBER_ID = 502;

    /**
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            if (! str_starts_with($request->url(), ClickUpClient::BASE_URL.'/')) {
                return null;
            }

            $path = self::path($request);

            foreach ($routes as $pattern => $response) {
                [$method, $glob] = explode(' ', $pattern, 2);

                if ($request->method() === $method && Str::is($glob, $path)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            throw new RuntimeException("Unexpected request: {$request->method()} {$request->url()}");
        });
    }

    public static function path(Request $request): string
    {
        return urldecode(Str::after($request->url(), ClickUpClient::BASE_URL.'/'));
    }

    /**
     * Teams of the token: the app workspace and optionally another one.
     *
     * @return array<string, mixed>
     */
    public static function teams(bool $withAppTeam = true): array
    {
        $teams = [[
            'id' => self::OTHER_TEAM_ID,
            'name' => 'Anderer Workspace',
            'members' => [['user' => ['id' => 777, 'username' => 'Fremd', 'email' => 'fremd@example.test']]],
        ]];

        if ($withAppTeam) {
            array_unshift($teams, [
                'id' => self::TEAM_ID,
                'name' => 'GUYCOLLE Test',
                'members' => [
                    ['user' => ['id' => self::MEMBER_ID, 'username' => 'Ada Admin', 'email' => 'ada@guycolle.test']],
                    ['user' => ['id' => self::OTHER_MEMBER_ID, 'username' => null, 'email' => 'max@guycolle.test']],
                ],
            ]);
        }

        return ['teams' => $teams];
    }

    /**
     * Hierarchy and members of the app workspace.
     *
     * @return array<string, mixed>
     */
    public static function workspaceRoutes(): array
    {
        return [
            'GET team/'.self::TEAM_ID.'/space?archived=false' => Http::response(['spaces' => [['id' => '301', 'name' => 'Kunden']]]),
            'GET space/301/folder?archived=false' => Http::response(['folders' => [['id' => '401', 'name' => 'Projekte']]]),
            'GET folder/401/list?archived=false' => Http::response(['lists' => [['id' => '901', 'name' => 'Website']]]),
            'GET space/301/list?archived=false' => Http::response(['lists' => [['id' => '902', 'name' => 'Allgemein']]]),
            'GET team' => Http::response(self::teams()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function task(string $id = 'abc123'): array
    {
        return ['id' => $id, 'url' => "https://app.clickup.com/t/{$id}", 'name' => 'Offerte Website'];
    }
}
