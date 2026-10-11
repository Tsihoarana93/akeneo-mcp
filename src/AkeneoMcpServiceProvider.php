<?php

namespace Antonny\AkeneoMcp;

use Antonny\AkeneoMcp\Servers\AkeneoServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class AkeneoMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/akeneo-mcp.php', 'akeneo-mcp');
    }

    public function boot(): void
    {
        Mcp::local('akeneo', AkeneoServer::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/akeneo-mcp.php' => config_path('akeneo-mcp.php'),
            ], 'akeneo-mcp-config');
        }
    }
}
