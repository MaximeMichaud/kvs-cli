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

        if (
            $state->getValue('register_all_commands_for_help') === true
            && $app instanceof Application
            && $config instanceof Configuration
        ) {
            $app->registerKvsCommands($config);
            $state->setValue('commands_registered', true);
        } elseif ($kvsAvailable === true && $app instanceof Application && $config instanceof Configuration) {
            $app->registerKvsCommands($config);
            $state->setValue('commands_registered', true);
        } elseif (
            $state->getValue('skip_kvs_context') === true
            && $app instanceof Application
            && $config instanceof Configuration
        ) {
            $app->registerStandaloneContextCommands($config);
            $state->setValue('commands_registered', true);
        } else {
            $state->setValue('commands_registered', false);
        }

        return $state;
    }
}
