<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The bundled updater copies an archive from freescout-help-desk/freescout over
 * base_path(), which on this fork overwrites the core patches our search modules
 * hook into and leaves no trace in the UI. config/app.php's disable_self_update
 * exists to stop that; these tests exist because if the guard stops holding,
 * nothing visible changes until an upgrade silently breaks customer search.
 *
 * Every test here swaps the Updater facade for a stub that records rather than
 * updates. That is not ceremony. Writing this suite against the real facade ran
 * an actual self-update on a dev checkout on 3 Aug 2026: it overwrote the fork's
 * patches, added stray upstream files and destroyed vendor/, and needed a git
 * restore plus a full composer reinstall to recover. A test for a guard must not
 * be able to do the thing the guard prevents when the guard fails, which is
 * precisely when the test reaches that code.
 *
 * The stub also sharpens the assertion from "we got an error back" to "the
 * updater was never reached", and that distinction caught a real bug: an early
 * version of the guard let a null config value through, while the action still
 * returned an error (the stub reports failure) and so still looked correct.
 *
 * The entry points are exercised directly rather than over HTTP, because the
 * real middleware stack calls header() in ResponseHeaders, which throws once
 * PHPUnit has written its own output.
 */
class DisableSelfUpdateTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Replaces the Updater facade so no test in this class can reach the real
     * one, and so reaching it at all is itself a failure.
     */
    protected function forbidRealUpdater()
    {
        // A hand-written stub rather than Mockery: the pinned Mockery 1.1.0
        // cannot reflect a concrete class on PHP 8.2, and mocking the string
        // 'updater' silently hijacks the Updater facade itself, since PHP class
        // names are case-insensitive.
        //
        // It records rather than throws, deliberately. SystemController wraps
        // the update in catch (\Exception), so a throwing stub would be caught
        // and returned as the very error message these tests assert on: the
        // test would pass while the updater had in fact been reached.
        $stub = new NeverRunsUpdaterStub(app());

        \Updater::swap($stub);

        return $stub;
    }

    protected function updateAction()
    {
        $controller = new \App\Http\Controllers\SystemController();
        $request = Request::create('/system/ajax', 'POST', ['action' => 'update']);

        return json_decode($controller->ajax($request)->getContent(), true);
    }

    /**
     * Evaluates config/app.php with the relevant environment variables cleared,
     * so what comes back is the default the file ships rather than whatever the
     * machine running the tests happens to have set.
     *
     * That distinction matters here: APP_DISABLE_SELF_UPDATE=false is the
     * documented way to re-enable updating, so a developer who had used it would
     * otherwise fail the test asserting the shipped default. Clearing and
     * restoring is enough because this fork's env() reads through getenv() only
     * (see overrides/laravel/framework/src/Illuminate/Support/helpers.php).
     *
     * Evaluating the file, rather than pattern-matching its text, keeps the
     * assertion about the value the app actually receives — a text match would
     * still pass if the expression were later wrapped in something that changed
     * the result.
     */
    protected function shippedConfig(array $keys)
    {
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = getenv($key);
            putenv($key);
        }

        try {
            return require base_path('config/app.php');
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== false) {
                    putenv($key.'='.$value);
                }
            }
        }
    }

    /**
     * The default the file ships, which is what a server picks up when it runs
     * config:cache on deploy.
     */
    public function test_the_config_file_ships_it_disabled()
    {
        $config = $this->shippedConfig(['APP_DISABLE_SELF_UPDATE']);

        $this->assertTrue($config['disable_self_update']);
    }

    /**
     * Version checking is deliberately left on, so the status page still shows
     * when an upstream release lands and we know to plan a sync. Only applying
     * it is blocked.
     */
    public function test_version_checking_is_not_disabled_too()
    {
        $config = $this->shippedConfig(['APP_DISABLE_UPDATING']);

        $this->assertFalse((bool) $config['disable_updating']);
    }

    /**
     * Hiding the button is not enough on its own: /system/ajax is a plain POST
     * any admin could send directly, so the action itself has to refuse.
     */
    public function test_the_update_action_refuses_while_disabled()
    {
        $stub = $this->forbidRealUpdater();
        config(['app.disable_self_update' => true]);

        $response = $this->updateAction();

        $this->assertSame('error', $response['status']);
        $this->assertNotEmpty($response['msg']);
        $this->assertFalse($stub->updateWasCalled, 'the real updater must never be reached');
    }

    /**
     * The case that caused the incident above. An install that ran config:cache
     * before this key existed has a cached config without it, so the lookup
     * returns null and a bare truthiness check lets the update through. Every
     * call site passes an explicit true default so absent reads as disabled.
     */
    public function test_the_update_action_refuses_when_the_config_key_is_absent()
    {
        $stub = $this->forbidRealUpdater();
        config(['app.disable_self_update' => null]);

        $response = $this->updateAction();

        $this->assertSame('error', $response['status']);
        $this->assertFalse($stub->updateWasCalled, 'the real updater must never be reached');
    }

    /**
     * The console command is the second way in, and the likelier one to end up
     * in a cron or deploy script. Exiting non-zero means a script calling it
     * fails loudly instead of appearing to have updated.
     */
    public function test_the_console_command_refuses_while_disabled()
    {
        $stub = $this->forbidRealUpdater();
        config(['app.disable_self_update' => true]);

        $exitCode = \Artisan::call('freescout:update', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('disabled', \Artisan::output());
    }

    /**
     * --force exists to skip the production confirmation prompt, so the guard
     * has to sit in front of that rather than behind it.
     */
    public function test_force_does_not_get_past_the_console_guard()
    {
        $this->forbidRealUpdater();
        config(['app.disable_self_update' => null]);

        $this->assertSame(1, \Artisan::call('freescout:update', ['--force' => true]));
    }

    /**
     * The other half of the rule: only an explicit false opens the gate. Without
     * this, a guard that simply always refused would pass every test above while
     * having quietly removed the ability to update at all, which is not what was
     * asked for — the deliberate opt-out has to keep working.
     */
    public function test_an_explicit_false_still_permits_updating()
    {
        $stub = $this->forbidRealUpdater();
        config(['app.disable_self_update' => false]);

        $this->updateAction();

        $this->assertTrue($stub->updateWasCalled, 'setting it to false must re-enable updating');
    }
}

/**
 * Stands in for the Updater facade so no test in this file can trigger a real
 * self-update. Records whether it was reached; see forbidRealUpdater() for why it
 * records instead of throwing.
 */
class NeverRunsUpdaterStub extends \Codedge\Updater\UpdaterManager
{
    public $updateWasCalled = false;

    public function update($version = '')
    {
        $this->updateWasCalled = true;

        return false;
    }

    public function isNewVersionAvailable($version = '')
    {
        return true;
    }

    public function getVersionAvailable($prepend = '', $append = '')
    {
        return '1.8.232';
    }
}
