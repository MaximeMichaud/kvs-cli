<?php

namespace KVS\CLI\Bootstrap;

use KVS\CLI\Application;
use KVS\CLI\Config\Configuration;

/**
 * Bootstrap Step: Register Commands
 *
 * Registers all KVS commands with the application.
 * Only runs if KVS installation is available.
 */
class RegisterCommands implements BootstrapStep
{
    public function process(BootstrapState $state): BootstrapState
    {
        $app = $state->getValue('application');
        $config = $state->getValue('config');
        $kvsAvailable = $state->getValue('kvs_available');

        // Only register commands if we have a valid KVS installation
        if ($kvsAvailable && $app instanceof Application && $config instanceof Configuration) {
            $app->registerKvsCommands($config);
            $state->setValue('commands_registered', true);
        } else {
            $state->setValue('commands_registered', false);
        }

        return $state;
    }
}
