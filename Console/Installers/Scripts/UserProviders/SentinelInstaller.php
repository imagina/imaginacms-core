<?php

namespace Modules\Core\Console\Installers\Scripts\UserProviders;

use Modules\Core\Console\Installers\SetupScript;

class SentinelInstaller extends ProviderInstaller implements SetupScript
{
    /**
     * Check if the user driver is correctly registered.
     */
    public function checkIsInstalled(): bool
    {
        return class_exists('Cartalyst\Sentinel\Laravel\SentinelServiceProvider');
    }

    /**
     * Not called
     *
     * @return mixed
     */
    public function composer()
    {
        $this->composer->enableOutput($this->command);
        $this->composer->install('cartalyst/sentinel:dev-feature/laravel-5');
        $this->composer->remove('cartalyst/sentry');
        $this->composer->dumpAutoload();

        // Dynamically register the service provider, so we can use it during publishing
        $this->application->register('Cartalyst\Sentinel\Laravel\SentinelServiceProvider');
    }

    /**
     * @return mixed
     */
    public function publish()
    {
        if ($this->command->option('verbose')) {
            return $this->command->call('vendor:publish', ['--provider' => 'Cartalyst\Sentinel\Laravel\SentinelServiceProvider']);
        }

        return $this->command->callSilent('vendor:publish', ['--provider' => 'Cartalyst\Sentinel\Laravel\SentinelServiceProvider']);
    }

    /**
     * @return mixed
     */
    public function migrate()
    {
    }

    /**
     * @return mixed
     */
    public function configure()
    {
        $this->replaceCartalystUserModelConfiguration(
            'Cartalyst\Sentinel\Users\EloquentUser',
            'Sentinel'
        );

        $this->bindUserRepositoryOnTheFly('Sentinel');
    }

    /**
     * @return mixed
     */
    public function seed()
    {
        if ($this->command->option('verbose')) {
            return $this->command->call('db:seed', ['--class' => 'Modules\User\Database\Seeders\SentinelGroupSeedTableSeeder']);
        }

        return $this->command->callSilent('db:seed', ['--class' => 'Modules\User\Database\Seeders\SentinelGroupSeedTableSeeder']);
    }

    /**
     * @return mixed
     */
    public function getHashedPassword($password)
    {
        return $password;
    }

}
