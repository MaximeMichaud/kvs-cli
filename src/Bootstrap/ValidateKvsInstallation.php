<?php

namespace KVS\CLI\Bootstrap;

use KVS\CLI\Config\Configuration;

/**
 * Bootstrap Step: Validate KVS Installation
 *
 * Checks if a valid KVS installation was found.
 * Sets kvs_available flag and adds error if not found.
 */
class ValidateKvsInstallation implements BootstrapStep
{
    public function process(BootstrapState $state): BootstrapState
    {
        $config = $state->getValue('config');

        if (!$config || !($config instanceof Configuration)) {
            $state->addError('KVS installation not found');
            $state->setValue('kvs_available', false);
            return $state;
        }

        if (!$config->isKvsInstalled()) {
            $state->addError('KVS installation not found');
            $state->setValue('kvs_available', false);

            // Store path info for error messages
            $searchedPath = $config->getKvsPath();
            if ($searchedPath) {
                $state->setValue('searched_path', $searchedPath);
            }
        } else {
            $state->setValue('kvs_available', true);
        }

        return $state;
    }
}
