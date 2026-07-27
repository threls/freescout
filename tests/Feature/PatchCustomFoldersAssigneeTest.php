<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers the agentfolders:patch-custom-folders command (ARMS-46), which
 * adds an Assignee field to the paid Custom Folders module's folder
 * create/update form and save handler. Custom Folders itself isn't
 * installed in this repo (paid, runtime-installed, not git-tracked - see
 * the command's own docblock for why it needs a direct file patch rather
 * than an Eventy hook), so these tests run the command against fixture
 * files that mirror the real module's form and controller exactly, rather
 * than the genuine files.
 *
 * The fixtures' exact whitespace is what makes the command's occurrence-
 * count guard meaningful to test here - it does NOT prove the real files
 * on the demo server are byte-identical (they were transcribed from a
 * terminal paste, not verified byte-for-byte). The guard is exactly what
 * protects the real files if that transcription is off: a mismatch means
 * an unexpected occurrence count, and the command refuses to touch
 * anything rather than guessing.
 */
class PatchCustomFoldersAssigneeTest extends TestCase
{
    protected $formPath;
    protected $controllerPath;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/AgentFolders/Console/PatchCustomFoldersAssignee.php';

        $this->formPath = sys_get_temp_dir().'/form_update_'.uniqid().'.blade.php';
        $this->controllerPath = sys_get_temp_dir().'/CustomFoldersController_'.uniqid().'.php';

        File::put($this->formPath, $this->formFixture());
        File::put($this->controllerPath, $this->controllerFixture());

        config([
            'agentfolders.form_patch_target' => $this->formPath,
            'agentfolders.controller_patch_target' => $this->controllerPath,
        ]);

        $this->app->register(\Modules\AgentFolders\Providers\AgentFoldersServiceProvider::class);
    }

    protected function tearDown(): void
    {
        foreach ([$this->formPath, $this->controllerPath] as $path) {
            File::delete($path);
            File::delete($path.'.bak');
        }

        parent::tearDown();
    }

    protected function formFixture()
    {
        return <<<'BLADE'
<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Show Only To') }}</label>

    <div class="col-sm-10">
        <select name="user_id" class="form-control">
            <option value=""></option>
            @php
                if (!isset($mailbox)) {
                    $mailbox = $folder->mailbox;
                }
            @endphp
            @foreach($mailbox->usersHavingAccess(true) as $user)
                <option value="{{ $user->id }}" @if ($folder->user_id && $folder->user_id == $user->id) selected @endif>{{ $user->getFullName() }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Status') }}</label>
</div>
BLADE;
    }

    protected function controllerFixture()
    {
        // Indentation matches the real controller's exact transcribed
        // depth (nested inside a switch/case) - CONTROLLER_ANCHOR is
        // written at that same depth, not a shallower stand-in.
        return <<<'PHP'
<?php
class CustomFoldersController
{
    public function ajax($request)
    {
        switch ($request->action) {
            case 'create':
            case 'update':
                if (true) {
                    if (true) {
                        $meta['icon'] = $request->icon;
                        $folder->meta = $meta;
                        $folder->user_id = $request->user_id;
                        $folder->save();
                    }
                }
                break;
        }
    }
}
PHP;
    }

    public function test_patches_both_form_and_controller()
    {
        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));

        $form = File::get($this->formPath);
        $this->assertStringContainsString('name="assignee_id"', $form);
        $this->assertStringContainsString(__('Assignee'), $form);

        $controller = File::get($this->controllerPath);
        $this->assertStringContainsString("meta['assignee_id']", $controller);
    }

    public function test_is_idempotent()
    {
        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));
        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));

        $form = File::get($this->formPath);
        $this->assertSame(1, substr_count($form, 'name="assignee_id"'));
    }

    public function test_creates_backups_before_patching()
    {
        $originalForm = File::get($this->formPath);
        $originalController = File::get($this->controllerPath);

        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));

        $this->assertTrue(File::exists($this->formPath.'.bak'));
        $this->assertSame($originalForm, File::get($this->formPath.'.bak'));

        $this->assertTrue(File::exists($this->controllerPath.'.bak'));
        $this->assertSame($originalController, File::get($this->controllerPath.'.bak'));
    }

    public function test_refuses_to_patch_form_when_occurrence_count_is_unexpected()
    {
        File::put($this->formPath, "<div>not the expected form</div>");
        $original = File::get($this->formPath);

        $this->assertSame(1, \Artisan::call('agentfolders:patch-custom-folders'));

        $this->assertSame($original, File::get($this->formPath), 'form must be untouched when the guard fails');
        $this->assertFalse(File::exists($this->formPath.'.bak'));
    }

    public function test_refuses_to_patch_controller_when_occurrence_count_is_unexpected()
    {
        File::put($this->controllerPath, "<?php class CustomFoldersController {}\n");
        $original = File::get($this->controllerPath);

        $this->assertSame(1, \Artisan::call('agentfolders:patch-custom-folders'));

        $this->assertSame($original, File::get($this->controllerPath), 'controller must be untouched when the guard fails');
        $this->assertFalse(File::exists($this->controllerPath.'.bak'));
    }

    public function test_no_ops_cleanly_when_custom_folders_is_not_installed()
    {
        config([
            'agentfolders.form_patch_target' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.blade.php',
            'agentfolders.controller_patch_target' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.php',
        ]);

        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));
    }

    public function test_revert_removes_the_patch_from_both_files()
    {
        $originalForm = File::get($this->formPath);
        $originalController = File::get($this->controllerPath);

        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));
        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders', ['--revert' => true]));

        $this->assertSame($originalForm, File::get($this->formPath));
        $this->assertSame($originalController, File::get($this->controllerPath));
    }

    /**
     * Anchors are written with plain \n. A file carrying CRLF line endings
     * (e.g. checked out or edited on Windows) must still match.
     */
    public function test_patches_files_with_crlf_line_endings()
    {
        File::put($this->formPath, str_replace("\n", "\r\n", $this->formFixture()));
        File::put($this->controllerPath, str_replace("\n", "\r\n", $this->controllerFixture()));

        $this->assertSame(0, \Artisan::call('agentfolders:patch-custom-folders'));

        $this->assertStringContainsString('name="assignee_id"', File::get($this->formPath));
        $this->assertStringContainsString("meta['assignee_id']", File::get($this->controllerPath));
    }

    /**
     * A read failure must be reported and exit non-zero, not throw an
     * uncaught TypeError from passing `false` to strpos()/substr_count().
     */
    public function test_fails_gracefully_when_form_is_unreadable()
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses file permissions, so this cannot be simulated running as root.');
        }

        chmod($this->formPath, 0000);

        $this->assertSame(1, \Artisan::call('agentfolders:patch-custom-folders'));

        chmod($this->formPath, 0644); // restore so tearDown() can delete it
    }
}
