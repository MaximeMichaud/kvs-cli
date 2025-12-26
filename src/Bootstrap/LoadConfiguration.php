<?php

namespace KVS\CLI\Bootstrap;

use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Bootstrap Step: Load Configuration
 *
 * Loads KVS configuration from various sources in order of priority:
 * 1. CLI argument --path
 * 2. Environment variable KVS_PATH
 * 3. Current working directory
 */
class LoadConfiguration implements BootstrapStep
{
    public function process(BootstrapState $state): BootstrapState
    {
        $input = $state->getValue('input');
        $pathOption = $this->extractPathOption($input);

        $configArray = [];
        if ($pathOption !== null) {
            $configArray['path'] = $pathOption;
        }

        try {
            $config = new Configuration($configArray);
            $state->setValue('config', $config);
            $state->setValue('config_loaded', true);
        } catch (\Exception $e) {
            $state->addError('Configuration loading failed: ' . $e->getMessage());
            $state->setValue('config_loaded', false);
        }

        return $state;
    }

    /**
     * Extract --path option from input
     */
    private function extractPathOption(?InputInterface $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // Try to get from input options
        try {
            if ($input->hasOption('path')) {
                return $input->getOption('path');
            }
        } catch (\Exception $e) {
            // Input not fully parsed yet, fallback to argv
        }

        // Fallback: parse from argv directly
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--path=')) {
                return substr($arg, 7);
            }
        }

        return null;
    }
}
