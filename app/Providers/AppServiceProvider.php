<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Name given to the robot user that authors macro-generated threads.
     * See relabelMacrosRobotUser().
     */
    const MACROS_ROBOT_USER_NAME = 'Macro';

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // To avoid MySQL error in packages:
        // "SQLSTATE[42000]: Syntax error or access violation: 1071 Specified key was too long; max key length is 767 bytes"
        Schema::defaultStringLength(191);

        // threls fork patch: see the method.
        $this->relabelMacrosRobotUser();

        // Models observers
        \App\Mailbox::observe(\App\Observers\MailboxObserver::class);
        // Eloquent events for this table are not called automatically, so need to be called manually.
        //\App\MailboxUser::observe(\App\Observers\MailboxUserObserver::class);
        \App\Email::observe(\App\Observers\EmailObserver::class);
        \App\User::observe(\App\Observers\UserObserver::class);
        \App\Conversation::observe(\App\Observers\ConversationObserver::class);
        \App\Customer::observe(\App\Observers\CustomerObserver::class);
        \App\Thread::observe(\App\Observers\ThreadObserver::class);
        \App\Attachment::observe(\App\Observers\AttachmentObserver::class);
        \App\Follower::observe(\App\Observers\FollowerObserver::class);
        \Illuminate\Notifications\DatabaseNotification::observe(\App\Observers\DatabaseNotificationObserver::class);

        \Validator::extend('safehost', function ($attribute, $value, $parameters, $validator) {
            if (!$value) {
                return true;
            }
            $msg = '';
            try {
                $url = $value;
                if (!preg_match("#^https?://#", $value)) {
                    $url = 'https://'.$url;
                }
                \Helper::sanitizeRemoteUrl($url, true);
            } catch (\Exception $e) {
                $msg = $e->getMessage();
            }
            if ($msg) {
                $validator->errors()->add($attribute, $msg);
                return false;
            }

            return true;
        });
    }

    /**
     * threls fork patch (ARMS-49): make the robot user that authors
     * macro-generated threads read "Macro" rather than "Workflow".
     *
     * That word reaches the screen as *data*, not as a translatable string, so
     * the resources/lang/en.json rename cannot touch it: the Workflows module
     * (paid, not tracked in this repo) creates a User::TYPE_ROBOT account named
     * from config('workflows.user_full_name'), and rewrites that account's name
     * to match the config on every run. It surfaces as the thread author on
     * every note a macro posts, and inside core's own
     * ":person forwarded this conversation" line.
     *
     * The module reads that config at runtime, so setting it here is enough —
     * no file of the module's is touched, and the change survives updates of
     * it. Order does not matter either: mergeConfigFrom() merges a module's own
     * file *under* whatever is already set, so this wins whether the module
     * registers before or after this provider boots.
     *
     * Deliberately unconditional, which means the module's own
     * WORKFLOWS_USER_FULL_NAME env var no longer does anything. That is the
     * point — an env var set on one environment and forgotten on the next is
     * exactly how this label would regress at go-live. Change the constant
     * above to relabel, not the environment.
     *
     * @return void
     */
    protected function relabelMacrosRobotUser()
    {
        \Config::set('workflows.user_full_name', self::MACROS_ROBOT_USER_NAME);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Forse HTTPS if using CloudFlare "Flexible SSL"
        // https://support.cloudflare.com/hc/en-us/articles/200170416-What-do-the-SSL-options-mean-
        if (\Helper::isHttps()) {
            // $_SERVER['HTTPS'] = 'on';
            // $_SERVER['SERVER_PORT'] = '443';
            $this->app['url']->forceScheme('https');
        }

        // If APP_KEY is not set, redirect to /install.php
        if (!\Config::get('app.key') && !app()->runningInConsole() && !file_exists(storage_path('.installed'))) {
            // Not defined here yet
            //\Artisan::call("freescout:clear-cache");
            redirect(\Helper::getSubdirectory().'/install.php')->send();
        }

        // Process module registration error - disable module and show error to admin
        \Eventy::addFilter('modules.register_error', function ($exception, $module) {

            $msg = __('The :module_name module has been deactivated due to an error: :error_message', ['module_name' => $module->getName(), 'error_message' => $exception->getMessage()]);

            \Log::error($msg);

            // request() does is empty at this stage
            if (!empty($_POST['action']) && $_POST['action'] == 'activate') {

                // During module activation in case of any error we have to deactivate module.
                \App\Module::deactiveModule($module->getAlias());

                \Session::flash('flashes_floating', [[
                    'text' => $msg,
                    'type' => 'danger',
                    'role' => \App\User::ROLE_ADMIN,
                ]]);

                return;
            } elseif (empty($_POST)) {

                // failed to open stream: No such file or directory
                if (strstr($exception->getMessage(), 'No such file or directory')) {
                    \App\Module::deactiveModule($module->getAlias());

                    \Session::flash('flashes_floating', [[
                        'text' => $msg,
                        'type' => 'danger',
                        'role' => \App\User::ROLE_ADMIN,
                    ]]);
                }

                return;
            }

            return $exception;
        }, 10, 2);
    }
}
