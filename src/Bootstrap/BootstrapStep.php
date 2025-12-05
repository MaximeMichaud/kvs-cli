<?php

namespace KVS\CLI\Bootstrap;

/**
 * Interface for bootstrap steps
 *
 * Each step in the bootstrap process implements this interface
 * and receives the current state, processes it, and returns the modified state.
 */
interface BootstrapStep
{
    /**
     * Process this bootstrapping step
     *
     * @param BootstrapState $state Current bootstrap state
     * @return BootstrapState Modified state to pass to next step
     */
    public function process(BootstrapState $state): BootstrapState;
}
