<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * ARMS-49: macro-generated threads must show "Macro" as their author. That name
 * is an account's, not a translatable string, so en.json can't reach it (see
 * WorkflowsToMacrosLabelTest for the part it does). Workflows isn't installed
 * here, so these cover the config value it would read.
 */
class MacrosRobotUserNameTest extends TestCase
{
    public function test_workflows_robot_user_is_named_macro()
    {
        $this->assertSame('Macro', config('workflows.user_full_name'));
    }

    /**
     * The approach depends on the module registering its own config file not
     * clobbering ours, whichever order they boot in. Emulated rather than
     * trusted, since it falls over the other way round.
     */
    public function test_the_module_merging_its_own_config_later_does_not_win()
    {
        $module_defaults = ['user_full_name' => 'Workflow'];

        // Cast: the key is absent entirely when Workflows isn't installed.
        $already_set = (array) config('workflows', []);

        // What ServiceProvider::mergeConfigFrom() does.
        config(['workflows' => array_merge($module_defaults, $already_set)]);

        $this->assertSame('Macro', config('workflows.user_full_name'));
    }

    public function test_the_name_comes_from_the_provider_constant()
    {
        $this->assertSame(
            AppServiceProvider::MACROS_ROBOT_USER_NAME,
            config('workflows.user_full_name')
        );
    }
}
