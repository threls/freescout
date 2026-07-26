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
 * currently-registered status with no further wiring — deactivate Send &
 * Close before activating this one to avoid two competing buttons.
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
    }

    public function hooks()
    {
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(self::MODULE_ALIAS).'/js/module.js';

            return $javascripts;
        });

        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(self::MODULE_ALIAS).'/css/style.css';

            return $styles;
        });

        // Renders at the very top of the Send dropdown, above core's own
        // "Send and stay on page" / "Send and next active" items.
        // 3 args (not the default 1): editor_bottom_toolbar.blade.php calls
        // @action('conversation.prepend_send_dropdown', $conversation, $mailbox,
        // $new_converstion ?? false) — Eventy truncates a listener's args down
        // to whatever count it was registered with, so $new_conversation would
        // silently always be null without this (see the identical note in
        // Modules/SortableCustomFields's provider).
        \Eventy::addAction('conversation.prepend_send_dropdown', function ($conversation, $mailbox, $new_conversation = false) {
            $this->renderSendStatusActions($new_conversation);
        }, 20, 3);
    }

    /**
     * A brand-new outgoing conversation has no prior status to leave — only
     * render these on existing conversations, same gate core already uses for
     * "Conversation History" / "Change default redirect" in this dropdown.
     */
    protected function renderSendStatusActions($new_conversation)
    {
        if ($new_conversation) {
            return;
        }

        echo '<li class="sas-primary-item"><a href="#" class="btn btn-success sas-send-status" data-send-status="'.(int) Conversation::STATUS_CLOSED.'">'.e(__('Send & Solve')).'</a></li>';

        foreach (array_keys(Conversation::$statuses) as $status) {
            if (in_array($status, [Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM])) {
                continue;
            }

            echo '<li><a href="#" class="sas-send-status" data-send-status="'.(int) $status.'">'.e(__('Send as').' '.Conversation::statusCodeToName($status)).'</a></li>';
        }

        echo '<li class="divider"></li>';
    }
}
