<?php

namespace Antonny\AkeneoMcp\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetProductsTool extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Retrieve a paginated list of products from the Akeneo PIM.
        Supports filtering by search term and pagination.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $token = $this->getAccessToken();

        $query = array_filter([
            'limit'      => $request->get('limit', 10),
            'page'       => $request->get('page', 1),
            'with_count' => 'true',
        ]);

        $search = $request->get('search');
        if ($search) {
            $query['search'] = json_encode([
                'label' => [['operator' => 'CONTAINS', 'value' => $search, 'locale' => 'en_US']],
            ]);
        }

        $response = Http::withToken($token)
            ->get(config('akeneo-mcp.base_url') . '/api/rest/v1/products', $query);

        if ($response->failed()) {
            return Response::text('Akeneo API error: ' . $response->status() . ' ' . $response->body());
        }

        $data = $response->json();
        $items = collect($data['_embedded']['items'] ?? [])
            ->map(fn($p) => [
                'identifier' => $p['identifier'],
                'enabled'    => $p['enabled'],
                'family'     => $p['family'],
                'categories' => $p['categories'],
                'values'     => $p['values'],
            ]);

        return Response::text(json_encode([
            'total'    => $data['items_count'] ?? null,
            'page'     => $request->get('page', 1),
            'limit'    => $request->get('limit', 10),
            'products' => $items,
        ], JSON_PRETTY_PRINT));
    }

    public function outputSchema($schema): array
    {
        return [];
    }

    public function schema($schema): array
    {
        return [
            'search' => $schema->string()->description('Filter products by label (case-insensitive contains match)'),
            'limit'  => $schema->integer()->description('Number of products per page (default: 10, max: 100)'),
            'page'   => $schema->integer()->description('Page number, starting at 1 (default: 1)'),
        ];
    }

    private function getAccessToken(): string
    {
        return Cache::remember('akeneo_access_token', 3500, function () {
            $response = Http::withBasicAuth(
                config('akeneo-mcp.client_id'),
                config('akeneo-mcp.client_secret')
            )->post(config('akeneo-mcp.base_url') . '/api/oauth/v1/token', [
                'grant_type' => 'password',
                'username'   => config('akeneo-mcp.username'),
                'password'   => config('akeneo-mcp.password'),
            ]);

            return $response->json('access_token');
        });
    }
}
