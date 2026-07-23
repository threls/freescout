<?php

namespace Modules\AuditLog\Providers;

use Illuminate\Support\ServiceProvider;

define('AUDITLOG_MODULE', 'auditlog');

/**
 * Cross-ticket audit log (ARMS-25).
 *
 * FreeScout records every ticket action as an immutable `threads` line-item,
 * but only ever shows them inside the individual conversation. This module
 * adds a standalone page that lists them across all tickets the viewer can
 * see, with filtering (agent / action type / mailbox / date range / ticket /
 * free-text) and CSV export. It changes nothing about how events are recorded.
 *
 * Query logic lives in Services/ so the December portal phase can expose the
 * same audit feed via API without re-implementation.
 */
class AuditLogServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'auditlog');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    public function hooks()
    {
        // Nav: an "Audit" item in the top menu. Shown to any authenticated
        // user — the page itself scopes results to the mailboxes they can
        // view (admins see all), so this exposes no ticket an agent couldn't
        // already open. To restrict to admins only, guard this with
        // ->isAdmin() here and change the route group's roles to ['admin'].
        \Eventy::addAction('menu.append', function () {
            if (auth()->check()) {
                echo \View::make('auditlog::menu')->render();
            }
        });
    }

    public function register()
    {
    }
}
