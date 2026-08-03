<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class Update extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:update {--force : Force the operation to run when in production.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update application to the latest version from GitHub';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // threls fork patch: same block as the web action in SystemController,
        // repeated because this command is a second way in and the likelier one
        // to end up in a cron or a deploy script by mistake. Checked before
        // confirmToProceed(), so --force cannot get past it. See
        // config/app.php disable_self_update for why.
        if (\Config::get('app.disable_self_update', true) !== false) {
            $this->error('Self-updating is disabled on this installation.');
            $this->line('This is a fork of FreeScout with patches to core files. The bundled updater');
            $this->line('fetches an archive from freescout-help-desk/freescout and copies it over the');
            $this->line('app, which silently drops those patches and breaks customer search.');
            $this->line('Upgrade by merging upstream into a sync branch and deploying it instead.');

            return 1;
        }

        if (!$this->confirmToProceed()) {
            return;
        }

        @ini_set('memory_limit', '128M');

        if (\Updater::isNewVersionAvailable(config('app.version'))) {
            $this->info('Updating... This may take several minutes');

            try {
                // Script may fail here and stop with the error:
                // PHP Fatal error:  Allowed memory size of 94371840 bytes exhausted
                \Updater::update();
                $this->call('freescout:after-app-update');
            } catch (\Exception $e) {
                $this->error('Error occurred: '.$e->getMessage());
            }
        } else {
            $this->info('You have the latest version installed: '.config('app.version'));
        }
    }
}
