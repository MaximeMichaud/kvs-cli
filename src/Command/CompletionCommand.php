<?php

declare(strict_types=1);

namespace KVS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates shell completion scripts.
 *
 * Works in PHAR by embedding scripts directly instead of reading from Resources/.
 */
class CompletionCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('completion')
            ->setDescription('Dump the shell completion script')
            ->addArgument('shell', InputArgument::OPTIONAL, 'The shell type (bash, zsh, fish)', $this->guessShell())
            ->addOption('install', 'i', InputOption::VALUE_NONE, 'Install the completion script automatically')
            ->setHelp(<<<'HELP'
Generate or install shell completion script.

<info>Automatic install:</info>
  <comment>kvs completion --install</comment>        Install for current shell
  <comment>kvs completion bash -i</comment>         Install for bash

<info>Manual install:</info>

<info>Bash:</info>
  <comment>kvs completion bash >> ~/.bash_completion</comment>
  Or system-wide:
  <comment>kvs completion bash | sudo tee /etc/bash_completion.d/kvs</comment>

<info>Zsh:</info>
  <comment>kvs completion zsh > ~/.zsh/completion/_kvs</comment>
  Make sure <comment>~/.zsh/completion</comment> is in your <comment>$fpath</comment>

<info>Fish:</info>
  <comment>kvs completion fish > ~/.config/fish/completions/kvs.fish</comment>

After installation, restart your shell or run:
  <comment>source ~/.bash_completion</comment> (bash)
  <comment>source ~/.zshrc</comment> (zsh)
  <comment>source ~/.config/fish/completions/kvs.fish</comment> (fish)
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $shell = $input->getArgument('shell');
        // PHPStan: shell is non-empty-string (has default from guessShell())

        /** @var bool $install */
        $install = $input->getOption('install');

        $script = match ($shell) {
            'bash' => $this->getBashScript(),
            'zsh' => $this->getZshScript(),
            'fish' => $this->getFishScript(),
            default => null,
        };

        if ($script === null) {
            $io->error(sprintf('Shell "%s" not supported. Use bash, zsh, or fish.', $shell));
            return Command::FAILURE;
        }

        if ($install) {
            return $this->installScript($io, $shell, $script);
        }

        $output->write($script, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function installScript(SymfonyStyle $io, string $shell, string $script): int
    {
        $path = $this->getInstallPath($shell);

        if ($path === null) {
            $io->error(sprintf('Could not determine install path for %s.', $shell));
            return Command::FAILURE;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $io->error(sprintf('Cannot create directory: %s', $dir));
            return Command::FAILURE;
        }

        if (!is_writable($dir)) {
            $io->error(sprintf('Cannot write to: %s - try with sudo', $dir));
            return Command::FAILURE;
        }

        if (file_put_contents($path, $script) === false) {
            $io->error(sprintf('Failed to write to: %s', $path));
            return Command::FAILURE;
        }

        $io->success(sprintf('Installed to: %s', $path));
        $io->text($this->getPostInstallHint($shell));

        return Command::SUCCESS;
    }

    private function getPostInstallHint(string $shell): string
    {
        return match ($shell) {
            'bash' => 'Restart your terminal or run: source ~/.bash_completion',
            'zsh' => 'Restart your terminal or run: source ~/.zshrc',
            'fish' => 'Restart your terminal or run: source ~/.config/fish/completions/kvs.fish',
            default => 'Restart your terminal to load completions',
        };
    }

    private function getInstallPath(string $shell): ?string
    {
        $homeEnv = getenv('HOME');
        $home = is_string($homeEnv) ? $homeEnv : '/root';
        $isRoot = posix_getuid() === 0;

        return match ($shell) {
            'bash' => $isRoot ? '/etc/bash_completion.d/kvs' : $home . '/.bash_completion',
            'zsh' => $home . '/.zsh/completion/_kvs',
            'fish' => $home . '/.config/fish/completions/kvs.fish',
            default => null,
        };
    }

    private function guessShell(): string
    {
        $shell = basename((string) getenv('SHELL'));
        return in_array($shell, ['bash', 'zsh', 'fish'], true) ? $shell : 'bash';
    }

    private function getBashScript(): string
    {
        return <<<'BASH'
# KVS CLI bash completion
# Generated by kvs completion bash

_kvs_complete() {
    local cur prev words cword
    _init_completion -n : || return

    _kvs_complete_files() {
        if declare -F _filedir >/dev/null; then
            _filedir
        else
            COMPREPLY=($(compgen -f -- "${cur}"))
        fi
    }

    local commands="help list self-update selfupdate self:update completion cli:info info
        system:status status system:cache cache system:cron cron system:backup backup system:check check
        system:benchmark benchmark bench system:queue queue system:server server servers system:conversion conversion
        system:email email system:antispam antispam system:stats stats system:stats-settings stats-settings
        maintenance maint
        content:video video videos content:album album albums gallery content:user user users member members
        user:purge users:purge user:cleanup content:category category categories cat content:tag tag tags
        content:comment comment comments content:model model models performer performers content:dvd dvd dvds channel channels
        content:playlist playlist playlists
        plugin plugins plug config conf cfg shell console repl eval eval-php eval-file
        db:export database:export db:dump db:import database:import db:restore
        dev:debug debug dev:log log logs
        video:formats formats video:screenshots screenshots
        settings:options options option settings:video-format video-format vformat
        migrate:scan scan migrate:package package migrate:import import migrate:to-docker to-docker"

    local video_actions="list show delete stats"
    local album_actions="list show delete"
    local user_actions="list show create delete stats"
    local category_actions="list tree show create delete update enable disable merge assign-group"
    local tag_actions="list show create delete merge update enable disable stats"
    local comment_actions="list pending show stats approve reject delete"
    local model_actions="list show stats"
    local dvd_actions="list show stats"
    local playlist_actions="list show create add remove delete"
    local plugin_actions="list show path status"
    local config_actions="list get set edit"
    local queue_actions="list show stats history help-action"
    local server_actions="list show enable disable activate deactivate stats group"
    local conversion_actions="list show enable disable activate deactivate debug-on debug-off log config stats"
    local email_actions="show test set log templates"
    local antispam_actions="show set add remove blacklist"
    local stats_settings_actions="show set"
    local options_actions="list get set"
    local video_format_actions="list show groups"
    local formats_actions="list check available"
    local screenshots_actions="list generate regenerate"
    local maintenance_actions="on off status"
    local completion_actions="bash zsh fish"
    local file_command_options="--help --path --output --target --force --no-content --compression --domain --email --ssl --db --yes"

    case "${prev}" in
        --path|-o|--output|-t|--target|-i|--includes|-b|--bootstrap)
            _kvs_complete_files
            return
            ;;
    esac

    if [[ ${cword} -eq 1 ]]; then
        COMPREPLY=($(compgen -W "${commands}" -- "${cur}"))
        return
    fi

    case "${words[1]}" in
        eval-file|db:import|database:import|db:restore|migrate:scan|scan|migrate:package|package|migrate:import|import|migrate:to-docker|to-docker)
            if [[ "${cur}" == -* ]]; then
                COMPREPLY=($(compgen -W "${file_command_options}" -- "${cur}"))
            else
                _kvs_complete_files
            fi
            ;;
        db:export|database:export|db:dump)
            COMPREPLY=($(compgen -W "--output --tables --no-data --compress --help --path" -- "${cur}"))
            ;;
        shell|console|repl)
            COMPREPLY=($(compgen -W "--includes --bootstrap --help --path" -- "${cur}"))
            ;;
        content:video|video|videos)
            COMPREPLY=($(compgen -W "${video_actions}" -- "${cur}"))
            ;;
        content:album|album|albums|gallery)
            COMPREPLY=($(compgen -W "${album_actions}" -- "${cur}"))
            ;;
        content:user|user|users|member|members)
            COMPREPLY=($(compgen -W "${user_actions}" -- "${cur}"))
            ;;
        content:category|category|categories|cat)
            COMPREPLY=($(compgen -W "${category_actions}" -- "${cur}"))
            ;;
        content:tag|tag|tags)
            COMPREPLY=($(compgen -W "${tag_actions}" -- "${cur}"))
            ;;
        content:comment|comment|comments)
            COMPREPLY=($(compgen -W "${comment_actions}" -- "${cur}"))
            ;;
        content:model|model|models|performer|performers)
            COMPREPLY=($(compgen -W "${model_actions}" -- "${cur}"))
            ;;
        content:dvd|dvd|dvds|channel|channels)
            COMPREPLY=($(compgen -W "${dvd_actions}" -- "${cur}"))
            ;;
        content:playlist|playlist|playlists)
            COMPREPLY=($(compgen -W "${playlist_actions}" -- "${cur}"))
            ;;
        plugin|plugins|plug)
            COMPREPLY=($(compgen -W "${plugin_actions}" -- "${cur}"))
            ;;
        config|conf|cfg)
            COMPREPLY=($(compgen -W "${config_actions}" -- "${cur}"))
            ;;
        system:queue|queue)
            COMPREPLY=($(compgen -W "${queue_actions}" -- "${cur}"))
            ;;
        system:server|server|servers)
            COMPREPLY=($(compgen -W "${server_actions}" -- "${cur}"))
            ;;
        system:conversion|conversion)
            COMPREPLY=($(compgen -W "${conversion_actions}" -- "${cur}"))
            ;;
        system:email|email)
            COMPREPLY=($(compgen -W "${email_actions}" -- "${cur}"))
            ;;
        system:antispam|antispam)
            COMPREPLY=($(compgen -W "${antispam_actions}" -- "${cur}"))
            ;;
        system:stats-settings|stats-settings)
            COMPREPLY=($(compgen -W "${stats_settings_actions}" -- "${cur}"))
            ;;
        settings:options|options|option)
            COMPREPLY=($(compgen -W "${options_actions}" -- "${cur}"))
            ;;
        settings:video-format|video-format|vformat)
            COMPREPLY=($(compgen -W "${video_format_actions}" -- "${cur}"))
            ;;
        video:formats|formats)
            COMPREPLY=($(compgen -W "${formats_actions}" -- "${cur}"))
            ;;
        video:screenshots|screenshots)
            COMPREPLY=($(compgen -W "${screenshots_actions}" -- "${cur}"))
            ;;
        maintenance|maint)
            COMPREPLY=($(compgen -W "${maintenance_actions}" -- "${cur}"))
            ;;
        completion)
            COMPREPLY=($(compgen -W "${completion_actions}" -- "${cur}"))
            ;;
        *)
            COMPREPLY=($(compgen -W "--help --path --format --fields --limit" -- "${cur}"))
            ;;
    esac
}

complete -F _kvs_complete kvs
BASH;
    }

    private function getZshScript(): string
    {
        return <<<'ZSH'
#compdef kvs

# KVS CLI zsh completion
# Generated by kvs completion zsh

_kvs() {
    local -a commands
    commands=(
        'help:Display help for a command'
        'list:List commands'
        'self-update:Update KVS CLI to latest version'
        'selfupdate:Update KVS CLI to latest version'
        'self\:update:Update KVS CLI to latest version'
        'completion:Dump shell completion script'
        'cli\:info:Display CLI environment information'
        'info:Display CLI environment information'
        'system\:status:Show system status'
        'status:Show system status'
        'system\:cache:Manage cache'
        'cache:Manage cache'
        'system\:cron:Run cron tasks'
        'cron:Run cron tasks'
        'system\:backup:Create/list backups'
        'backup:Create/list backups'
        'system\:check:Check system health'
        'check:Check system health'
        'system\:benchmark:Run performance benchmarks'
        'benchmark:Run performance benchmarks'
        'bench:Run performance benchmarks'
        'system\:queue:Manage background task queue'
        'queue:Manage background task queue'
        'system\:server:Manage storage servers'
        'server:Manage storage servers'
        'servers:Manage storage servers'
        'system\:conversion:Manage conversion servers'
        'conversion:Manage conversion servers'
        'system\:email:Manage email settings'
        'email:Manage email settings'
        'system\:antispam:Manage anti-spam settings'
        'antispam:Manage anti-spam settings'
        'system\:stats:Show site statistics'
        'stats:Show site statistics'
        'system\:stats-settings:Manage statistics settings'
        'stats-settings:Manage statistics settings'
        'maintenance:Manage maintenance mode'
        'maint:Manage maintenance mode'
        'content\:video:Manage videos'
        'video:Manage videos'
        'videos:Manage videos'
        'content\:album:Manage albums'
        'album:Manage albums'
        'albums:Manage albums'
        'gallery:Manage albums'
        'content\:user:Manage users'
        'user:Manage users'
        'users:Manage users'
        'member:Manage users'
        'members:Manage users'
        'user\:purge:Purge users'
        'users\:purge:Purge users'
        'user\:cleanup:Purge users'
        'content\:category:Manage categories'
        'category:Manage categories'
        'categories:Manage categories'
        'cat:Manage categories'
        'content\:tag:Manage tags'
        'tag:Manage tags'
        'tags:Manage tags'
        'content\:comment:Manage comments'
        'comment:Manage comments'
        'comments:Manage comments'
        'content\:model:Manage models'
        'model:Manage models'
        'models:Manage models'
        'performer:Manage models'
        'performers:Manage models'
        'content\:dvd:Manage DVDs'
        'dvd:Manage DVDs'
        'dvds:Manage DVDs'
        'channel:Manage DVDs'
        'channels:Manage DVDs'
        'content\:playlist:Manage playlists'
        'playlist:Manage playlists'
        'playlists:Manage playlists'
        'plugin:Manage plugins'
        'plugins:Manage plugins'
        'plug:Manage plugins'
        'config:View configuration'
        'conf:View configuration'
        'cfg:View configuration'
        'shell:Interactive PHP shell'
        'console:Interactive PHP shell'
        'repl:Interactive PHP shell'
        'eval:Execute PHP code'
        'eval-php:Execute PHP code'
        'eval-file:Execute PHP file'
        'db\:export:Export database'
        'database\:export:Export database'
        'db\:dump:Export database'
        'db\:import:Import database'
        'database\:import:Import database'
        'db\:restore:Import database'
        'dev\:debug:Toggle debug mode'
        'debug:Toggle debug mode'
        'dev\:log:View logs'
        'log:View logs'
        'logs:View logs'
        'video\:formats:Show video formats'
        'formats:Show video formats'
        'video\:screenshots:Manage screenshots'
        'screenshots:Manage screenshots'
        'settings\:options:Manage KVS system options'
        'options:Manage KVS system options'
        'option:Manage KVS system options'
        'settings\:video-format:Manage KVS video formats'
        'video-format:Manage KVS video formats'
        'vformat:Manage KVS video formats'
        'migrate\:scan:Scan a KVS installation'
        'scan:Scan a KVS installation'
        'migrate\:package:Create a migration package'
        'package:Create a migration package'
        'migrate\:import:Import a migration package'
        'import:Import a migration package'
        'migrate\:to-docker:Migrate to Docker'
        'to-docker:Migrate to Docker'
    )

    _arguments -C \
        '--path=[Path to KVS installation]:directory:_files -/' \
        '--help[Display help]' \
        '1: :->command' \
        '*:: :->args'

    case $state in
        command)
            _describe -t commands 'kvs command' commands
            ;;
        args)
            case $words[CURRENT-1] in
                --path|-o|--output|-t|--target|-i|--includes|-b|--bootstrap)
                    _files
                    return
                    ;;
            esac

            case $words[1] in
                eval-file|db:import|database:import|db:restore|migrate:scan|scan|\
migrate:package|package|migrate:import|import|migrate:to-docker|to-docker)
                    if [[ $PREFIX == -* ]]; then
                        _arguments \
                            '--help[Display help]' \
                            '--path=[Path to KVS installation]:directory:_files -/' \
                            '--output=[Output file path]:file:_files' \
                            '-o[Output file path]:file:_files' \
                            '--target=[Target directory]:directory:_files -/' \
                            '-t[Target directory]:directory:_files -/' \
                            '--includes=[Additional files to include]:file:_files' \
                            '-i[Additional files to include]:file:_files' \
                            '--bootstrap=[Bootstrap file to load]:file:_files' \
                            '-b[Bootstrap file to load]:file:_files'
                    else
                        _files
                    fi
                    ;;
                db:export|database:export|db:dump)
                    _arguments \
                        '--help[Display help]' \
                        '--path=[Path to KVS installation]:directory:_files -/' \
                        '--output=[Output file path]:file:_files' \
                        '-o[Output file path]:file:_files' \
                        '--tables=[Specific tables to export]:tables:' \
                        '--no-data[Export structure only]' \
                        '--compress=[Compression format]:format:(gzip zstd xz bzip2)'
                    ;;
                shell|console|repl)
                    _arguments \
                        '--help[Display help]' \
                        '--path=[Path to KVS installation]:directory:_files -/' \
                        '--includes=[Additional files to include]:file:_files' \
                        '-i[Additional files to include]:file:_files' \
                        '--bootstrap=[Bootstrap file to load]:file:_files' \
                        '-b[Bootstrap file to load]:file:_files'
                    ;;
                content:video|video|videos)
                    _arguments '1:action:(list show delete stats)'
                    ;;
                content:album|album|albums|gallery)
                    _arguments '1:action:(list show delete)'
                    ;;
                content:user|user|users|member|members)
                    _arguments '1:action:(list show create delete stats)'
                    ;;
                content:model|model|models|performer|performers)
                    _arguments '1:action:(list show stats)'
                    ;;
                content:dvd|dvd|dvds|channel|channels)
                    _arguments '1:action:(list show stats)'
                    ;;
                content:category|category|categories|cat)
                    _arguments '1:action:(list tree show create delete update enable disable merge assign-group)'
                    ;;
                content:tag|tag|tags)
                    _arguments '1:action:(list show create delete merge update enable disable stats)'
                    ;;
                content:comment|comment|comments)
                    _arguments '1:action:(list pending show stats approve reject delete)'
                    ;;
                content:playlist|playlist|playlists)
                    _arguments '1:action:(list show create add remove delete)'
                    ;;
                plugin|plugins|plug)
                    _arguments '1:action:(list show path status)'
                    ;;
                config|conf|cfg)
                    _arguments '1:action:(list get set edit)'
                    ;;
                system:queue|queue)
                    _arguments '1:action:(list show stats history help-action)'
                    ;;
                system:server|server|servers)
                    _arguments '1:action:(list show enable disable activate deactivate stats group)'
                    ;;
                system:conversion|conversion)
                    _arguments '1:action:(list show enable disable activate deactivate debug-on debug-off log config stats)'
                    ;;
                system:email|email)
                    _arguments '1:action:(show test set log templates)'
                    ;;
                system:antispam|antispam)
                    _arguments '1:action:(show set add remove blacklist)'
                    ;;
                system:stats-settings|stats-settings)
                    _arguments '1:action:(show set)'
                    ;;
                settings:options|options|option)
                    _arguments '1:action:(list get set)'
                    ;;
                settings:video-format|video-format|vformat)
                    _arguments '1:action:(list show groups)'
                    ;;
                video:formats|formats)
                    _arguments '1:action:(list check available)'
                    ;;
                video:screenshots|screenshots)
                    _arguments '1:action:(list generate regenerate)'
                    ;;
                maintenance|maint)
                    _arguments '1:action:(on off status)'
                    ;;
                completion)
                    _arguments '1:shell:(bash zsh fish)'
                    ;;
            esac
            ;;
    esac
}

_kvs "$@"
ZSH;
    }

    private function getFishScript(): string
    {
        return <<<'FISH'
# KVS CLI fish completion
# Generated by kvs completion fish

# Disable file completion by default
complete -c kvs -f

# Main commands
complete -c kvs -n "__fish_use_subcommand" -a "help" -d "Display help"
complete -c kvs -n "__fish_use_subcommand" -a "list" -d "List commands"
complete -c kvs -n "__fish_use_subcommand" -a "self-update" -d "Update KVS CLI"
complete -c kvs -n "__fish_use_subcommand" -a "selfupdate" -d "Update KVS CLI"
complete -c kvs -n "__fish_use_subcommand" -a "self:update" -d "Update KVS CLI"
complete -c kvs -n "__fish_use_subcommand" -a "completion" -d "Shell completion"
complete -c kvs -n "__fish_use_subcommand" -a "cli:info" -d "CLI environment"
complete -c kvs -n "__fish_use_subcommand" -a "info" -d "CLI environment"
complete -c kvs -n "__fish_use_subcommand" -a "system:status" -d "System status"
complete -c kvs -n "__fish_use_subcommand" -a "status" -d "System status"
complete -c kvs -n "__fish_use_subcommand" -a "system:cache" -d "Manage cache"
complete -c kvs -n "__fish_use_subcommand" -a "cache" -d "Manage cache"
complete -c kvs -n "__fish_use_subcommand" -a "system:cron" -d "Run cron"
complete -c kvs -n "__fish_use_subcommand" -a "cron" -d "Run cron"
complete -c kvs -n "__fish_use_subcommand" -a "system:backup" -d "Create/list backups"
complete -c kvs -n "__fish_use_subcommand" -a "backup" -d "Create/list backups"
complete -c kvs -n "__fish_use_subcommand" -a "system:check" -d "Check health"
complete -c kvs -n "__fish_use_subcommand" -a "check" -d "Check health"
complete -c kvs -n "__fish_use_subcommand" -a "system:benchmark" -d "Run benchmarks"
complete -c kvs -n "__fish_use_subcommand" -a "benchmark" -d "Run benchmarks"
complete -c kvs -n "__fish_use_subcommand" -a "bench" -d "Run benchmarks"
complete -c kvs -n "__fish_use_subcommand" -a "system:queue" -d "Task queue"
complete -c kvs -n "__fish_use_subcommand" -a "queue" -d "Task queue"
complete -c kvs -n "__fish_use_subcommand" -a "system:server" -d "Storage servers"
complete -c kvs -n "__fish_use_subcommand" -a "server" -d "Storage servers"
complete -c kvs -n "__fish_use_subcommand" -a "servers" -d "Storage servers"
complete -c kvs -n "__fish_use_subcommand" -a "system:conversion" -d "Conversion servers"
complete -c kvs -n "__fish_use_subcommand" -a "conversion" -d "Conversion servers"
complete -c kvs -n "__fish_use_subcommand" -a "system:email" -d "Email settings"
complete -c kvs -n "__fish_use_subcommand" -a "email" -d "Email settings"
complete -c kvs -n "__fish_use_subcommand" -a "system:antispam" -d "Anti-spam settings"
complete -c kvs -n "__fish_use_subcommand" -a "antispam" -d "Anti-spam settings"
complete -c kvs -n "__fish_use_subcommand" -a "system:stats" -d "Site statistics"
complete -c kvs -n "__fish_use_subcommand" -a "stats" -d "Site statistics"
complete -c kvs -n "__fish_use_subcommand" -a "system:stats-settings" -d "Stats settings"
complete -c kvs -n "__fish_use_subcommand" -a "stats-settings" -d "Stats settings"
complete -c kvs -n "__fish_use_subcommand" -a "maintenance" -d "Maintenance mode"
complete -c kvs -n "__fish_use_subcommand" -a "maint" -d "Maintenance mode"
complete -c kvs -n "__fish_use_subcommand" -a "content:video" -d "Manage videos"
complete -c kvs -n "__fish_use_subcommand" -a "video" -d "Manage videos"
complete -c kvs -n "__fish_use_subcommand" -a "videos" -d "Manage videos"
complete -c kvs -n "__fish_use_subcommand" -a "content:album" -d "Manage albums"
complete -c kvs -n "__fish_use_subcommand" -a "album" -d "Manage albums"
complete -c kvs -n "__fish_use_subcommand" -a "albums" -d "Manage albums"
complete -c kvs -n "__fish_use_subcommand" -a "gallery" -d "Manage albums"
complete -c kvs -n "__fish_use_subcommand" -a "content:user" -d "Manage users"
complete -c kvs -n "__fish_use_subcommand" -a "user" -d "Manage users"
complete -c kvs -n "__fish_use_subcommand" -a "users" -d "Manage users"
complete -c kvs -n "__fish_use_subcommand" -a "member" -d "Manage users"
complete -c kvs -n "__fish_use_subcommand" -a "members" -d "Manage users"
complete -c kvs -n "__fish_use_subcommand" -a "user:purge" -d "Purge users"
complete -c kvs -n "__fish_use_subcommand" -a "users:purge" -d "Purge users"
complete -c kvs -n "__fish_use_subcommand" -a "user:cleanup" -d "Purge users"
complete -c kvs -n "__fish_use_subcommand" -a "content:category" -d "Categories"
complete -c kvs -n "__fish_use_subcommand" -a "category" -d "Categories"
complete -c kvs -n "__fish_use_subcommand" -a "categories" -d "Categories"
complete -c kvs -n "__fish_use_subcommand" -a "cat" -d "Categories"
complete -c kvs -n "__fish_use_subcommand" -a "content:tag" -d "Manage tags"
complete -c kvs -n "__fish_use_subcommand" -a "tag" -d "Manage tags"
complete -c kvs -n "__fish_use_subcommand" -a "tags" -d "Manage tags"
complete -c kvs -n "__fish_use_subcommand" -a "content:comment" -d "Manage comments"
complete -c kvs -n "__fish_use_subcommand" -a "comment" -d "Manage comments"
complete -c kvs -n "__fish_use_subcommand" -a "comments" -d "Manage comments"
complete -c kvs -n "__fish_use_subcommand" -a "content:model" -d "Manage models"
complete -c kvs -n "__fish_use_subcommand" -a "model" -d "Manage models"
complete -c kvs -n "__fish_use_subcommand" -a "models" -d "Manage models"
complete -c kvs -n "__fish_use_subcommand" -a "performer" -d "Manage models"
complete -c kvs -n "__fish_use_subcommand" -a "performers" -d "Manage models"
complete -c kvs -n "__fish_use_subcommand" -a "content:dvd" -d "Manage DVDs"
complete -c kvs -n "__fish_use_subcommand" -a "dvd" -d "Manage DVDs"
complete -c kvs -n "__fish_use_subcommand" -a "dvds" -d "Manage DVDs"
complete -c kvs -n "__fish_use_subcommand" -a "channel" -d "Manage DVDs"
complete -c kvs -n "__fish_use_subcommand" -a "channels" -d "Manage DVDs"
complete -c kvs -n "__fish_use_subcommand" -a "content:playlist" -d "Manage playlists"
complete -c kvs -n "__fish_use_subcommand" -a "playlist" -d "Manage playlists"
complete -c kvs -n "__fish_use_subcommand" -a "playlists" -d "Manage playlists"
complete -c kvs -n "__fish_use_subcommand" -a "plugin" -d "Manage plugins"
complete -c kvs -n "__fish_use_subcommand" -a "plugins" -d "Manage plugins"
complete -c kvs -n "__fish_use_subcommand" -a "plug" -d "Manage plugins"
complete -c kvs -n "__fish_use_subcommand" -a "config" -d "Configuration"
complete -c kvs -n "__fish_use_subcommand" -a "conf" -d "Configuration"
complete -c kvs -n "__fish_use_subcommand" -a "cfg" -d "Configuration"
complete -c kvs -n "__fish_use_subcommand" -a "shell" -d "PHP shell"
complete -c kvs -n "__fish_use_subcommand" -a "console" -d "PHP shell"
complete -c kvs -n "__fish_use_subcommand" -a "repl" -d "PHP shell"
complete -c kvs -n "__fish_use_subcommand" -a "eval" -d "Execute PHP"
complete -c kvs -n "__fish_use_subcommand" -a "eval-php" -d "Execute PHP"
complete -c kvs -n "__fish_use_subcommand" -a "eval-file" -d "Execute PHP file"
complete -c kvs -n "__fish_use_subcommand" -a "db:export" -d "Export database"
complete -c kvs -n "__fish_use_subcommand" -a "database:export" -d "Export database"
complete -c kvs -n "__fish_use_subcommand" -a "db:dump" -d "Export database"
complete -c kvs -n "__fish_use_subcommand" -a "db:import" -d "Import database"
complete -c kvs -n "__fish_use_subcommand" -a "database:import" -d "Import database"
complete -c kvs -n "__fish_use_subcommand" -a "db:restore" -d "Import database"
complete -c kvs -n "__fish_use_subcommand" -a "dev:debug" -d "Debug mode"
complete -c kvs -n "__fish_use_subcommand" -a "debug" -d "Debug mode"
complete -c kvs -n "__fish_use_subcommand" -a "dev:log" -d "View logs"
complete -c kvs -n "__fish_use_subcommand" -a "log" -d "View logs"
complete -c kvs -n "__fish_use_subcommand" -a "logs" -d "View logs"
complete -c kvs -n "__fish_use_subcommand" -a "video:formats" -d "Video formats"
complete -c kvs -n "__fish_use_subcommand" -a "formats" -d "Video formats"
complete -c kvs -n "__fish_use_subcommand" -a "video:screenshots" -d "Screenshots"
complete -c kvs -n "__fish_use_subcommand" -a "screenshots" -d "Screenshots"
complete -c kvs -n "__fish_use_subcommand" -a "settings:options" -d "System options"
complete -c kvs -n "__fish_use_subcommand" -a "options" -d "System options"
complete -c kvs -n "__fish_use_subcommand" -a "option" -d "System options"
complete -c kvs -n "__fish_use_subcommand" -a "settings:video-format" -d "Video format settings"
complete -c kvs -n "__fish_use_subcommand" -a "video-format" -d "Video format settings"
complete -c kvs -n "__fish_use_subcommand" -a "vformat" -d "Video format settings"
complete -c kvs -n "__fish_use_subcommand" -a "migrate:scan" -d "Scan migration"
complete -c kvs -n "__fish_use_subcommand" -a "scan" -d "Scan migration"
complete -c kvs -n "__fish_use_subcommand" -a "migrate:package" -d "Package migration"
complete -c kvs -n "__fish_use_subcommand" -a "package" -d "Package migration"
complete -c kvs -n "__fish_use_subcommand" -a "migrate:import" -d "Import migration"
complete -c kvs -n "__fish_use_subcommand" -a "import" -d "Import migration"
complete -c kvs -n "__fish_use_subcommand" -a "migrate:to-docker" -d "Migrate to Docker"
complete -c kvs -n "__fish_use_subcommand" -a "to-docker" -d "Migrate to Docker"

# Subcommand actions
complete -c kvs -n "__fish_seen_subcommand_from content:video video videos" -a "list show delete stats"
complete -c kvs -n "__fish_seen_subcommand_from content:album album albums gallery" -a "list show delete"
complete -c kvs -n "__fish_seen_subcommand_from content:user user users member members" -a "list show create delete stats"
complete -c kvs -n "__fish_seen_subcommand_from content:category category" -a "list tree show create delete update enable disable merge assign-group"
complete -c kvs -n "__fish_seen_subcommand_from categories cat" -a "list tree show create delete update enable disable merge assign-group"
complete -c kvs -n "__fish_seen_subcommand_from content:tag tag tags" -a "list show create delete merge update enable disable stats"
complete -c kvs -n "__fish_seen_subcommand_from content:comment comment comments" -a "list pending show stats approve reject delete"
complete -c kvs -n "__fish_seen_subcommand_from content:model model models performer performers" -a "list show stats"
complete -c kvs -n "__fish_seen_subcommand_from content:dvd dvd dvds channel channels" -a "list show stats"
complete -c kvs -n "__fish_seen_subcommand_from content:playlist playlist playlists" -a "list show create add remove delete"
complete -c kvs -n "__fish_seen_subcommand_from plugin plugins plug" -a "list show path status"
complete -c kvs -n "__fish_seen_subcommand_from config conf cfg" -a "list get set edit"
complete -c kvs -n "__fish_seen_subcommand_from system:queue queue" -a "list show stats history help-action"
complete -c kvs -n "__fish_seen_subcommand_from system:server server servers" -a "list show enable disable activate deactivate stats group"
complete -c kvs -n "__fish_seen_subcommand_from system:conversion conversion" -a "list show enable disable activate deactivate"
complete -c kvs -n "__fish_seen_subcommand_from system:conversion conversion" -a "debug-on debug-off log config stats"
complete -c kvs -n "__fish_seen_subcommand_from system:email email" -a "show test set log templates"
complete -c kvs -n "__fish_seen_subcommand_from system:antispam antispam" -a "show set add remove blacklist"
complete -c kvs -n "__fish_seen_subcommand_from system:stats-settings stats-settings" -a "show set"
complete -c kvs -n "__fish_seen_subcommand_from settings:options options option" -a "list get set"
complete -c kvs -n "__fish_seen_subcommand_from settings:video-format video-format vformat" -a "list show groups"
complete -c kvs -n "__fish_seen_subcommand_from video:formats formats" -a "list check available"
complete -c kvs -n "__fish_seen_subcommand_from video:screenshots screenshots" -a "list generate regenerate"
complete -c kvs -n "__fish_seen_subcommand_from maintenance maint" -a "on off status"
complete -c kvs -n "__fish_seen_subcommand_from completion" -a "bash zsh fish"

# Path and file arguments
complete -c kvs -n "__fish_seen_subcommand_from eval-file" -F
complete -c kvs -n "__fish_seen_subcommand_from db:import database:import db:restore" -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:scan scan" -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:package package" -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:import import" -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:to-docker to-docker" -F

# Path and file options
complete -c kvs -n "__fish_seen_subcommand_from db:export database:export db:dump" -s o -l output -d "Output file path" -r -F
complete -c kvs -n "__fish_seen_subcommand_from system:backup backup" -l output -d "Output directory" -r -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:package package" -s o -l output -d "Output file path" -r -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:import import" -s t -l target -d "KVS-Install directory" -r -F
complete -c kvs -n "__fish_seen_subcommand_from migrate:to-docker to-docker" -s t -l target -d "KVS-Install directory" -r -F
complete -c kvs -n "__fish_seen_subcommand_from shell console repl" -s i -l includes -d "Additional files to include" -r -F
complete -c kvs -n "__fish_seen_subcommand_from shell console repl" -s b -l bootstrap -d "Bootstrap file to load" -r -F

# Global options
complete -c kvs -l path -d "Path to KVS installation" -r
complete -c kvs -l help -d "Display help"
complete -c kvs -l format -d "Output format" -a "table json csv yaml count ids"
complete -c kvs -l fields -d "Fields to display" -r
complete -c kvs -l limit -d "Limit results" -r
FISH;
    }
}
