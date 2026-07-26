<?php

namespace Modules\SendAndSetStatus\Providers;

use App\Conversation;
use Illuminate\Support\ServiceProvider;

/**
 * Adds one-click "Send & <status>" actions to the reply/note editor's Send
 * dropdown.
 *
 * Today, changing status and sending are two separate steps: pick a value in
 * the Status <select> (editor_bottom_toolbar.blade.php), then click Send.
 * This module collapses that into a single click by rendering extra items in
 * the same dropdown (via the conversation.prepend_send_dropdown Blade action,
 * already present in core) and, in Public/js/module.js, setting the Status
 * select then triggering the existing Send button — no new backend endpoint,
 * this reuses the exact same POST the Status select + Send button already
 * produce.
 *
 * Supersedes the paid Send & Close module: that module is not tracked by
 * this repo's git (like Workflows — see Modules/OnHoldStatus's README) and,
 * more importantly, it hardcodes a single status (Closed) with no hook to
 * extend it, so it can never learn about On-Hold (status 5, registered at
 * runtime by Modules/OnHoldStatus). This module instead reads
 * Conversation::$statuses directly, so it automatically offers one item per
 * currently-registered status with no further wiring. Deactivating Send &
 * Close is still the right long-term move (no point loading two modules'
 * JS for one job), but this module's own CSS (Public/css/style.css) also
 * hides Send & Close's button outright if that manual step is ever
 * forgotten — confirmed live: both were active at once and Send & Close
 * kept showing up unchanged.
 *
 * The button markup and args deliberately mirror Send & Close's own real
 * source (pulled from the production install to verify this): a plain
 * <button type="button"> with btn/btn-block, not an <a>, and a
 * zero-argument listener rather than one that reads $conversation/$mailbox/
 * $new_conversation — Send & Close renders unconditionally, including on a
 * brand-new outgoing conversation, and this follows suit rather than adding
 * an untested restriction of its own.
 */
class SendAndSetStatusServiceProvider extends ServiceProvider
{
    const MODULE_ALIAS = 'sendandsetstatus';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register translations.
     *
     * Same locales and mechanism (loadJsonTranslationsFrom) Send & Close's
     * own Resources/lang ships, for the two strings this module introduces
     * ("Send & Solve" and "Send as") that aren't already covered by core's
     * or another module's translation files.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    public function hooks()
    {
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(self::MODULE_ALIAS).'/js/module.js';

            return $javascripts;
        });

        // Suppresses Send & Close's own button if that module is ever left
        // active alongside this one — see Public/css/style.css.
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(self::MODULE_ALIAS).'/css/style.css';

            return $styles;
        });

        // Renders at the very top of the Send dropdown, above core's own
        // "Send and stay on page" / "Send and next active" items.
        \Eventy::addAction('conversation.prepend_send_dropdown', function () {
            $this->renderSendStatusActions();
        });
    }

    protected function renderSendStatusActions()
    {
        echo '<li><button type="button" class="btn btn-success btn-block sas-send-status" data-send-status="'.(int) Conversation::STATUS_CLOSED.'">'.e(__('Send & Solve')).'</button></li>';

        foreach (array_keys(Conversation::$statuses) as $status) {
            if (in_array($status, [Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM])) {
                continue;
            }

            echo '<li><a href="#" class="sas-send-status" data-send-status="'.(int) $status.'">'.e(__('Send as').' '.Conversation::statusCodeToName($status)).'</a></li>';
        }

        echo '<li class="divider"></li>';
    }
}
