<?php

namespace Tests\Unit;

use App\Conversation;
use Tests\TestCase;

/**
 * Covers the SendAndSetStatus module: renders a primary "Send & Solve"
 * button plus a "Send as <status>" item per remaining registered status,
 * via the conversation.prepend_send_dropdown Blade action that
 * editor_bottom_toolbar.blade.php already fires.
 */
class SendAndSetStatusTest extends TestCase
{
    const ONHOLD = 5;

    protected $statuses_backup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The module is not autoloaded while inactive — load the provider directly.
        require_once __DIR__.'/../../Modules/SendAndSetStatus/Providers/SendAndSetStatusServiceProvider.php';

        // Static array persists across tests in the same process — back it up
        // (same precaution as OnHoldStatusTest).
        $this->statuses_backup = Conversation::$statuses;
    }

    protected function tearDown(): void
    {
        Conversation::$statuses = $this->statuses_backup;

        parent::tearDown();
    }

    protected function bootModule()
    {
        (new \Modules\SendAndSetStatus\Providers\SendAndSetStatusServiceProvider(app()))->boot();
    }

    protected function renderDropdown(...$args)
    {
        ob_start();
        \Eventy::action('conversation.prepend_send_dropdown', ...$args);

        return ob_get_clean();
    }

    public function test_primary_button_is_send_and_solve_targeting_closed()
    {
        $this->bootModule();

        $html = $this->renderDropdown();

        // e() escapes the & for HTML output.
        $this->assertStringContainsString('Send &amp; Solve', $html);
        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_CLOSED.'"', $html);
        $this->assertStringContainsString('<button type="button" class="btn btn-success btn-block sas-send-status"', $html);
    }

    public function test_secondary_items_cover_active_and_pending_but_not_closed_or_spam()
    {
        $this->bootModule();

        $html = $this->renderDropdown();

        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_ACTIVE.'"', $html);
        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_PENDING.'"', $html);
        $this->assertStringContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_ACTIVE), $html);
        $this->assertStringContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_PENDING), $html);

        // Closed is the primary button only — must not also appear as a
        // "Send as" secondary item.
        $this->assertStringNotContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_CLOSED), $html);

        // Spam is never a valid reply-time destination status.
        $this->assertStringNotContainsString('data-send-status="'.Conversation::STATUS_SPAM.'"', $html);
    }

    /**
     * The secondary set is read from the live registry, not a fixed list —
     * a module-registered status (On-Hold) must appear automatically with
     * no coupling to OnHoldStatus's own class.
     */
    public function test_module_registered_status_appears_automatically()
    {
        Conversation::$statuses[self::ONHOLD] = 'onhold';
        \Eventy::addFilter('conversation.status_name', function ($name, $status) {
            return $status == self::ONHOLD ? 'On Hold' : $name;
        }, 20, 2);

        $this->bootModule();

        $html = $this->renderDropdown();

        $this->assertStringContainsString('data-send-status="'.self::ONHOLD.'"', $html);
        $this->assertStringContainsString(__('Send as').' On Hold', $html);
    }

    /**
     * Matches the real Send & Close module's own behaviour: it renders
     * unconditionally, including when composing a brand-new outgoing
     * conversation (editor_bottom_toolbar.blade.php's
     * @action('conversation.prepend_send_dropdown', $conversation, $mailbox,
     * $new_converstion ?? false) call passes true there) — this listener
     * takes no parameters, so it ignores whatever's passed rather than
     * gating on it.
     */
    public function test_renders_even_when_composing_a_new_conversation()
    {
        $this->bootModule();

        $html = $this->renderDropdown(null, null, true);

        $this->assertStringContainsString('Send &amp; Solve', $html);
    }

    /**
     * Matches Send & Close's own Resources/lang mechanism
     * (loadJsonTranslationsFrom) for the two strings this module
     * introduces, so a non-English locale doesn't fall back to raw English.
     */
    public function test_translations_resolve_for_a_non_default_locale()
    {
        (new \Modules\SendAndSetStatus\Providers\SendAndSetStatusServiceProvider(app()))->register();

        $originalLocale = app()->getLocale();
        app()->setLocale('de');

        try {
            $this->assertSame('Senden & Lösen', __('Send & Solve'));
            $this->assertSame('Senden als', __('Send as'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /**
     * Every shipped language file must be valid JSON and carry both keys —
     * a malformed or incomplete file fails silently otherwise (Laravel just
     * falls back to the English key with no error).
     */
    public function test_every_shipped_translation_file_is_valid_and_complete()
    {
        $files = glob(__DIR__.'/../../Modules/SendAndSetStatus/Resources/lang/*.json');

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $decoded = json_decode(file_get_contents($file), true);

            $this->assertIsArray($decoded, "$file is not valid JSON");
            $this->assertArrayHasKey('Send & Solve', $decoded, "$file is missing 'Send & Solve'");
            $this->assertArrayHasKey('Send as', $decoded, "$file is missing 'Send as'");
        }
    }
}
