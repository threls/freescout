<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * ARMS-49: the robot user that authors macro-generated threads must read
 * "Macro", not "Workflow".
 *
 * Unlike the rest of that rename, this word is not a translatable string, so
 * resources/lang/en.json cannot reach it (see WorkflowsToMacrosLabelTest for
 * the part that is). The Workflows module names its robot account from
 * config('workflows.user_full_name') and rewrites the account to match on every
 * run, which is why editing the user row by hand does not hold. Overridden in
 * AppServiceProvider instead.
 *
 * Workflows is paid and not installed in this repo, so what is verified here is
 * the config value the module would read, plus the ordering assumption the
 * approach rests on.
 */
class MacrosRobotUserNameTest extends TestCase
{
    public function test_workflows_robot_user_is_named_macro()
    {
        $this->assertSame('Macro', config('workflows.user_full_name'));
    }

    /**
     * The load-bearing assumption: this works regardless of whether the module
     * registers before or after AppServiceProvider boots, because
     * mergeConfigFrom() merges a module's own config file *under* values that
     * are already set. Emulated here rather than trusted, since the whole
     * approach falls over if it is the other way round.
     */
    public function test_the_module_merging_its_own_config_later_does_not_win()
    {
        // What Modules/Workflows/Config/config.php ships.
        $module_defaults = ['user_full_name' => 'Workflow'];

        // Cast because the key does not exist at all when Workflows is absent,
        // which is the case in this repo.
        $already_set = (array) config('workflows', []);

        // Exactly what ServiceProvider::mergeConfigFrom() does.
        config(['workflows' => array_merge($module_defaults, $already_set)]);

        $this->assertSame('Macro', config('workflows.user_full_name'));
    }

    /**
     * Relabelling should mean editing one constant, not hunting through
     * environment files on each server.
     */
    public function test_the_name_comes_from_the_provider_constant()
    {
        $this->assertSame(
            AppServiceProvider::MACROS_ROBOT_USER_NAME,
            config('workflows.user_full_name')
        );
    }
}
