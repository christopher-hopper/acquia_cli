<?php

namespace AcquiaCli\Commands;

use Robo\Tasks;
use Robo\Robo;
use Acquia\Cloud\Api\CloudApiClient;
use Symfony\Component\Console\Helper\Table;

class DeployCommand extends Tasks
{
    /** @var CloudApiClient $cloudapi */
    protected $cloudapi;

    use \Boedah\Robo\Task\Drush\loadTasks;

    /**
     * This hook will fire for all commands in this command file.
     *
     * @hook init
     */
    public function construct() {
        $acquia = Robo::Config()->get('acquia');
        $cloudapi = CloudApiClient::factory(array(
            'username' => $acquia['mail'],
            'password' => $acquia['pass'],
        ));

        $this->cloudapi = $cloudapi;
    }

    /**
     * Runs a deployment of a branch/tag and config/db update to the production environment.
     *
     * @command prod:acquia:deploy
     */
    public function acquiaDeployProd($site, $branch) {
        $this->yell('WARNING: DEPLOYING TO PROD');
        if ($this->confirm('Are you sure you want to deploy to prod?')) {
            $this->acquiaDeployEnv($site, 'prod', $branch);
        }
    }

    /**
     * Runs a deployment of a branch/tag and config/db update to a non-production environment.
     *
     * @command acquia:deploy:preprod
     */
    public function acquiaDeployPreProd($site, $environment, $branch) {
        if ($environment == 'prod') {
            throw new \Exception('Use the prod:acquia:deploy command for the production environment.');
        }

        $this->acquiaDeployEnv($site, $environment, $branch);
    }

    /**
     * Updates configuration and db in production.
     *
     * @command prod:acquia:config-update
     */
    public function acquiaConfigUpdateProd($site) {
        $this->yell('WARNING: UPDATING CONFIG ON PROD');
        if ($this->confirm('Are you sure you want to update prod config? This will overwrite your prod configuration.')) {
            $this->acquiaConfigUpdate($site, 'prod');
        }
    }

    /**
     * Updates configuration and db in a non-production environment.
     *
     * @command acquia:config-update:preprod
     */
    public function acquiaConfigUpdatePreProd($site, $environment) {

        if ($environment == 'prod') {
            throw new \Exception('Use the prod:acquia:prepare command for the production environment.');
        }

        $this->acquiaConfigUpdate($site, $environment);
    }

    /**
     * Updates configuration and db in all non-production environments.
     *
     * @command acquia:config-update:preprod:all
     */
    public function acquiaConfigUpdatePreProdAll($site) {
        $environments = $this->cloudapi->environments($site);

        foreach ($environments as $environment) {
            $env = $environment->name();
            if ($env == 'prod') {
                continue;
            }

            $this->acquiaConfigUpdate($site, $env);
        }
    }

    /**
     * Prepares the production environment for a deployment.
     *
     * @command prod:acquia:prepare
     */
    public function acquiaPrepareProd($site)
    {
        $databases = $this->cloudapi->environmentDatabases($site, 'prod');
        foreach ($databases as $database) {

            $db = $database->name();
            $this->backupDb($site, 'prod', $db);
        }
    }

    /**
     * Prepares a non-production environment for a deployment.
     *
     * @command acquia:prepare:preprod
     */
    public function acquiaPreparePreProd($site, $environment)
    {

        if ($environment == 'prod') {
            throw new \Exception('Use the acquia:prepare:prod command for the production environment.');
        }

        $this->backupAndMoveDbs($site, $environment);
        $this->backupFiles($site, $environment);
    }

    /**
     * Prepares all non-production environments for a deployment.
     *
     * @command acquia:prepare:preprod:all
     */
    public function acquiaPreparePreProdAll($site)
    {
        $environments = $this->cloudapi->environments($site);
        foreach ($environments as $environment) {
            $env = $environment->name();
            if ($env == 'prod') {
                continue;
            }

            $this->backupAndMoveDbs($site, $env);
            $this->backupFiles($site, $env);
        }
    }


    /*************************************************************************/
    /*                         INTERNAL FUNCTIONS                            */
    /*************************************************************************/

    protected function backupAndMoveDbs($site, $environment) {
        $databases = $this->cloudapi->environmentDatabases($site, $environment);
        foreach ($databases as $database) {

            $db = $database->name();
            $this->backupDb($site, $environment, $db);

            // Copy DB from prod to non-prod.
            $this->say("Moving DB (${db}) from prod to ${environment}");
            $this->cloudapi->copyDatabase($site, $db, 'prod', $environment);
        }
    }

    protected function backupDb($site, $environment, $database) {
        // Run database backups.
        $this->say("Backing up DB (${database}) on ${environment}");
        $this->cloudapi->createDatabaseBackup($site, $environment, $database);
    }

    protected function backupFiles($site, $environment) {
        // Copy files from prod to non-prod.
        $this->say("Moving files from prod to ${environment}");
        $this->cloudapi->copyFiles($site, 'prod', $environment);
    }

    protected function isTaskComplete($site, $taskId) {
        $task = $this->cloudapi->task($site, $taskId);
        if ($task->completed()) {
            return TRUE;
        }
        return FALSE;
    }

    protected function acquiaDeployEnv($site, $environment, $branch)
    {
        $task = $this->cloudapi->pushCode($site, $environment, $branch);
        $taskId = $task->id();
        while (!$this->isTaskComplete($site, $taskId)) {
            $this->say('Waiting for code deployment...');
            sleep(1);
        }
        $this->acquiaConfigUpdate($site, $environment);
    }

    protected function acquiaConfigUpdate($site, $environment) {
        $site = $this->cloudapi->site($site);
        $siteName = $site->unixUsername();

        $task = $this->taskDrushStack()
            ->stopOnFail()
            ->siteAlias("@${siteName}.${environment}")
            ->clearCache('drush')
            ->drush("cache-rebuild")
            ->updateDb()
            ->drush(['pm-enable', 'config_split'])
            ->drush(['config-import', 'sync'])
            ->drush("cache-rebuild")
            ->run();

        // @TODO add domains
        //$this->cloudapi->purgeVarnishCache($site, $environment);
    }
}



