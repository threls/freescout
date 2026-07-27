<?php

namespace Modules\AgentFolders\Console;

use Illuminate\Console\Command;

/**
 * Adds an "Assignee" field to the paid Custom Folders module's folder
 * create/update form and save handler (ARMS-46), so a folder can be pinned
 * to one specific agent's conversations. See AgentFoldersServiceProvider's
 * docblock for why the actual filtering logic lives in this fork's own
 * Eventy hooks instead of here - this command only touches the two spots
 * needed to capture and persist which agent was picked in the UI.
 *
 * Custom Folders is a paid, runtime-installed module and is NOT tracked by
 * this repo's git (.gitignore's blanket /Modules/* rule has no allowlist
 * entry for it, unlike our own modules) - a module update or reinstall
 * silently replaces its files. This command is meant to be re-run on every
 * deploy (idempotent - a no-op if already patched) so the patch survives
 * that, the same way onholdstatus:patch-workflows already does for the
 * Workflows module (see Modules/OnHoldStatus/Console/PatchWorkflowsStatuses.php).
 */
class PatchCustomFoldersAssignee extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'agentfolders:patch-custom-folders {--revert : Remove the Assignee field instead of adding it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Add an Assignee field to Custom Folders' create/update form and save handler";

    const FORM_MARKER = 'name="assignee_id"';

    const FORM_ANCHOR = <<<'BLADE'
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
BLADE;

    const FORM_INSERTED = <<<'BLADE'


<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Assignee') }}</label>

    <div class="col-sm-10">
        <select name="assignee_id" class="form-control">
            <option value=""></option>
            @foreach($mailbox->usersHavingAccess(true) as $user)
                <option value="{{ $user->id }}" @if (!empty($folder->meta['assignee_id']) && $folder->meta['assignee_id'] == $user->id) selected @endif>{{ $user->getFullName() }}</option>
            @endforeach
        </select>
        <div class="form-help">{{ __('Only show conversations assigned to this specific agent, regardless of who is viewing the folder.') }}</div>
    </div>
</div>
BLADE;

    const CONTROLLER_MARKER = "meta['assignee_id']";

    const CONTROLLER_ANCHOR = <<<'PHP'
                        $meta['icon'] = $request->icon;
                        $folder->meta = $meta;
PHP;

    const CONTROLLER_INSERTED = <<<'PHP'
                        $meta['icon'] = $request->icon;
                        $meta['assignee_id'] = $request->assignee_id ?? '';
                        $folder->meta = $meta;
PHP;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $form_path = $this->formPath();
        $controller_path = $this->controllerPath();

        if (!file_exists($form_path) && !file_exists($controller_path)) {
            $this->line('Custom Folders module not installed — nothing to patch.');

            return 0;
        }

        $revert = (bool) $this->option('revert');

        $result = $this->patchOne($form_path, self::FORM_MARKER, self::FORM_ANCHOR, self::FORM_ANCHOR.self::FORM_INSERTED, $revert, 'form');
        if ($result !== 0) {
            return $result;
        }

        $result = $this->patchOne($controller_path, self::CONTROLLER_MARKER, self::CONTROLLER_ANCHOR, self::CONTROLLER_INSERTED, $revert, 'controller');
        if ($result !== 0) {
            return $result;
        }

        $this->info($revert
            ? 'Reverted: Assignee field removed from Custom Folders.'
            : "Patched: Custom Folders now has an Assignee field on its folder form.");

        return 0;
    }

    /**
     * Applies (or reverts) one anchor/insert pair against one file.
     * $unpatched and $patched are the file's content before/after the
     * change - direction just swaps which one we're searching for and
     * which one we're writing.
     */
    protected function patchOne($path, $marker, $unpatched, $patched, $revert, $label)
    {
        if (!file_exists($path)) {
            $this->line("Custom Folders {$label} file not found — skipping.");

            return 0;
        }

        $content = $this->readFile($path);
        if ($content === null) {
            return 1;
        }

        $has_marker = strpos($content, $marker) !== false;

        if (!$revert) {
            if ($has_marker) {
                $this->info("Already patched — Assignee field is already in the {$label}.");

                return 0;
            }

            $count = substr_count($content, $unpatched);
            if ($count !== 1) {
                $this->error(
                    "Expected exactly 1 occurrence of the expected {$label} content, found {$count}. ".
                    "Custom Folders may have changed shape since this patch was written — refusing to modify the file. ".
                    "Check {$path} manually and update PatchCustomFoldersAssignee's anchor for the {$label} if needed."
                );

                return 1;
            }

            if (!$this->backup($path, $content)) {
                return 1;
            }

            $new_content = str_replace($unpatched, $patched, $content);
        } else {
            if (!$has_marker) {
                $this->info("Not currently patched — nothing to revert in the {$label}.");

                return 0;
            }

            $count = substr_count($content, $patched);
            if ($count !== 1) {
                $this->error(
                    "Expected exactly 1 occurrence of the patched {$label} content, found {$count}. ".
                    "Refusing to modify the file — revert manually if the {$label} has been edited since patching."
                );

                return 1;
            }

            if (!$this->backup($path, $content)) {
                return 1;
            }

            $new_content = str_replace($patched, $unpatched, $content);
        }

        if (!$this->writeFile($path, $new_content)) {
            return 1;
        }

        return 0;
    }

    /**
     * Reads the file and normalizes CRLF to LF, since the anchors above are
     * written with plain \n - without this, a file that happens to carry
     * Windows line endings would never match, and the occurrence-count
     * guard would refuse it as if Custom Folders had genuinely changed
     * shape. Returns null (after printing an error) on a read failure,
     * rather than letting `false` reach strpos()/substr_count() - both
     * throw a TypeError on `false` under PHP 8.
     */
    protected function readFile($path)
    {
        // @-suppressed: a read failure is handled explicitly below via the
        // false return, not via PHP's warning (which this app's error
        // handler escalates to a thrown ErrorException).
        $content = @file_get_contents($path);
        if ($content === false) {
            $this->error("Failed to read {$path}.");

            return null;
        }

        return str_replace("\r\n", "\n", $content);
    }

    protected function writeFile($path, $content)
    {
        if (@file_put_contents($path, $content) === false) {
            $this->error("Failed to write {$path} — check permissions and disk space.");

            return false;
        }

        return true;
    }

    protected function backup($path, $content)
    {
        return $this->writeFile($path.'.bak', $content);
    }

    /**
     * Overridable so tests can point this at a fixture file instead of the
     * real (not-installed-in-this-repo) Custom Folders module.
     */
    protected function formPath()
    {
        return config('agentfolders.form_patch_target')
            ?: base_path('Modules/CustomFolders/Resources/views/partials/form_update.blade.php');
    }

    /**
     * Overridable so tests can point this at a fixture file instead of the
     * real (not-installed-in-this-repo) Custom Folders module.
     */
    protected function controllerPath()
    {
        return config('agentfolders.controller_patch_target')
            ?: base_path('Modules/CustomFolders/Http/Controllers/CustomFoldersController.php');
    }
}
