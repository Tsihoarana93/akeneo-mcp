<?php

namespace Antonny\AkeneoMcp\Tools;

use Antonny\AkeneoMcp\AkeneoClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class GetProductsTool extends Tool
{
    protected string $description = <<<'MARKDOWN'
        Retrieve a paginated list of products from the Akeneo PIM.
        Filter by identifier, family or enabled status, and limit the returned attribute values
        with `attributes` and `locale` to keep the response small.
        The total product count is only returned for the first page.
    MARKDOWN;

    public function handle(Request $request, AkeneoClient $akeneo): Response|ResponseFactory
    {
        $validated = $request->validate([
            'search'     => 'nullable|string',
            'family'     => 'nullable|string',
            'enabled'    => 'nullable|boolean',
            'attributes' => 'nullable|string',
            'locale'     => 'nullable|string',
            'limit'      => 'nullable|integer|min:1|max:100',
            'page'       => 'nullable|integer|min:1',
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $query = [
            'limit'      => $limit,
            'page'       => $page,
            'with_count' => $page === 1 ? 'true' : 'false',
        ];

        $search = $this->buildSearch($validated);

        if ($search !== []) {
            $query['search'] = json_encode($search);
        }

        if (! empty($validated['attributes'])) {
            $query['attributes'] = $validated['attributes'];
        }

        if (! empty($validated['locale'])) {
            $query['locales'] = $validated['locale'];
        }

        try {
            $data = $akeneo->request()
                ->get($akeneo->url('/api/rest/v1/products'), $query)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            return Response::error('Akeneo API error: ' . $exception->response->status() . ' ' . $exception->response->body());
        } catch (ConnectionException|RuntimeException $exception) {
            return Response::error('Akeneo API error: ' . $exception->getMessage());
        }

        $products = collect($data['_embedded']['items'] ?? [])
            ->map(fn (array $product): array => [
                'identifier' => $product['identifier'],
                'enabled'    => $product['enabled'],
                'family'     => $product['family'],
                'categories' => $product['categories'],
                'values'     => $product['values'],
            ])
            ->values()
            ->all();

        return Response::structured([
            'total'    => $data['items_count'] ?? null,
            'page'     => $page,
            'limit'    => $limit,
            'products' => $products,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search'     => $schema->string()->description('Only products whose identifier contains this text'),
            'family'     => $schema->string()->description('Only products of this family code'),
            'enabled'    => $schema->boolean()->description('Only enabled (true) or disabled (false) products'),
            'attributes' => $schema->string()->description('Comma-separated attribute codes to include in values, e.g. "name,description"'),
            'locale'     => $schema->string()->description('Only return values for this locale, e.g. "en_US"'),
            'limit'      => $schema->integer()->min(1)->max(100)->default(10)->description('Number of products per page'),
            'page'       => $schema->integer()->min(1)->default(1)->description('Page number, starting at 1'),
        ];
    }

    /**
     * Build Akeneo's `search` filter from the given arguments.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, list<array{operator: string, value: mixed}>>
     */
    private function buildSearch(array $validated): array
    {
        $search = [];

        if (! empty($validated['search'])) {
            $search['identifier'] = [['operator' => 'CONTAINS', 'value' => $validated['search']]];
        }

        if (! empty($validated['family'])) {
            $search['family'] = [['operator' => 'IN', 'value' => [$validated['family']]]];
        }

        if (isset($validated['enabled'])) {
            $search['enabled'] = [['operator' => '=', 'value' => (bool) $validated['enabled']]];
        }

        return $search;
    }
}
