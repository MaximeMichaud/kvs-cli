<?php

namespace KVS\CLI\Command\Traits;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks a command as experimental with a user-facing warning.
 *
 * Experimental commands display a warning on execution and require
 * explicit user confirmation before proceeding. Use --force to skip
 * the confirmation prompt (required for non-interactive mode).
 */
trait ExperimentalCommandTrait
{
    protected function configureExperimentalOption(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip experimental feature confirmation');
    }

    /**
     * Show experimental warning and require user acknowledgment.
     *
     * Call this at the start of execute(). Returns null to proceed,
     * or a Command exit code to abort.
     */
    protected function confirmExperimental(InputInterface $input, OutputInterface $output): ?int
    {
        if ($input->getOption('force') === true) {
            return null;
        }

        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error([
                'This command is EXPERIMENTAL and requires explicit acknowledgment.',
                'Use --force to run in non-interactive mode.',
            ]);
            return Command::FAILURE;
        }

        $io->warning([
            'EXPERIMENTAL FEATURE',
            'This command is experimental and under active development.',
            'It may not be fully functional and is NOT production-ready.',
            'Use at your own risk.',
        ]);

        if (!$io->confirm('Do you understand and wish to continue?', false)) {
            $io->text('Command aborted.');
            return Command::SUCCESS;
        }

        return null;
    }
}
