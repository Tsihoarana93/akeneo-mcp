<?php

namespace Antonny\AkeneoMcp\Servers;

use Antonny\AkeneoMcp\Tools\GetProductsTool;
use Laravel\Mcp\Server;

class AkeneoServer extends Server
{
    protected string $name = 'Akeneo Server';
    protected string $version = '0.0.1';
    protected string $instructions = <<<'MARKDOWN'
        MCP server for the Akeneo PIM REST API. Use the available tools to query products and related data.
    MARKDOWN;

    protected array $tools = [
        GetProductsTool::class,
    ];

    protected array $resources = [];
    protected array $prompts = [];
}
