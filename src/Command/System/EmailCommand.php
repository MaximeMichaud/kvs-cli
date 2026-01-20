<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:email',
    description: 'Manage KVS email settings',
    aliases: ['email']
)]
class EmailCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: show|test|set',
                'show'
            )
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP server hostname')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP server port')
            ->addOption('smtp-user', null, InputOption::VALUE_REQUIRED, 'SMTP username')
            ->addOption('smtp-pass', null, InputOption::VALUE_REQUIRED, 'SMTP password')
            ->addOption('smtp-security', null, InputOption::VALUE_REQUIRED, 'SMTP security (tls|ssl)')
            ->addOption('from-email', null, InputOption::VALUE_REQUIRED, 'From email address')
            ->addOption('from-name', null, InputOption::VALUE_REQUIRED, 'From display name')
            ->addOption('mailer', null, InputOption::VALUE_REQUIRED, 'Mailer type (php|smtp|custom)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Test email recipient')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Test email subject')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Test email body')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json', 'table')
            ->setHelp(<<<'HELP'
Manage KVS email settings.

<fg=yellow>ACTIONS:</>
  show          Display current email settings (default)
  test          Send a test email
  set           Update email settings

<fg=yellow>MAILER TYPES:</>
  php           Use PHP mail() function
  smtp          Use SMTP server
  custom        Use custom mail script

<fg=yellow>SMTP SECURITY:</>
  tls           TLS encryption (recommended)
  ssl           SSL encryption

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs email show</>
  <fg=green>kvs email show --format=json</>
  <fg=green>kvs email test --to=test@example.com</>
  <fg=green>kvs email test --to=test@example.com --subject="Test" --body="Hello"</>
  <fg=green>kvs email set --mailer=smtp</>
  <fg=green>kvs email set --smtp-host=smtp.gmail.com --smtp-port=587</>
  <fg=green>kvs email set --from-email=noreply@example.com</>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'show' => $this->showSettings($input),
            'test' => $this->testEmail($input),
            'set' => $this->setSettings($input),
            default => $this->showSettings($input),
        };
    }

    private function showSettings(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT value FROM {$this->table('settings')}
                WHERE section = 'email'
            ");
            $stmt->execute();
            $value = $stmt->fetchColumn();

            if ($value === false || !is_string($value)) {
                $this->io()->warning('No email settings found');
                return self::SUCCESS;
            }

            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                $this->io()->error('Invalid email settings format');
                return self::FAILURE;
            }
            /** @var array<string, mixed> $settings */
            $settings = $decoded;

            $format = $this->getStringOption($input, 'format');

            if ($format === 'json') {
                $this->io()->writeln((string) json_encode($settings, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            $this->io()->section('Email Settings');

            // General settings
            $mailer = $this->getSettingString($settings, 'use_mailer', 'php');
            $mailerLabels = [
                'php' => 'PHP mail()',
                'smtp' => 'SMTP Server',
                'custom' => 'Custom Script',
            ];

            $generalInfo = [
                ['Mailer', $mailerLabels[$mailer] ?? $mailer],
                ['From Email', $this->getSettingString($settings, 'send_from_email', '<not set>')],
                ['From Name', $this->getSettingString($settings, 'send_from_title', '<not set>')],
                ['Debug Level', $this->formatDebugLevel($this->getSettingString($settings, 'debug_level', '0'))],
            ];

            $this->renderTable(['Setting', 'Value'], $generalInfo);

            // SMTP settings (if using SMTP)
            if ($mailer === 'smtp') {
                $this->io()->newLine();
                $this->io()->section('SMTP Configuration');

                $smtpHost = $this->getSettingString($settings, 'smtp_host', '<not set>');
                $smtpPort = $this->getSettingInt($settings, 'smtp_port', 0);
                $smtpUser = $this->getSettingString($settings, 'smtp_username', '<not set>');
                $smtpPass = $this->getSettingString($settings, 'smtp_password', '');
                $smtpSecurity = $this->getSettingString($settings, 'smtp_security', 'tls');
                $smtpTimeout = $this->getSettingInt($settings, 'smtp_timeout', 20);

                $smtpInfo = [
                    ['Host', $smtpHost],
                    ['Port', (string) $smtpPort],
                    ['Username', $smtpUser],
                    ['Password', $this->maskPassword($smtpPass)],
                    ['Security', strtoupper($smtpSecurity)],
                    ['Timeout', $smtpTimeout . ' seconds'],
                ];

                $this->renderTable(['Setting', 'Value'], $smtpInfo);
            }

            // Test settings
            $testEmail = $this->getSettingString($settings, 'test_email', '');
            if ($testEmail !== '') {
                $this->io()->newLine();
                $this->io()->section('Last Test');
                $this->io()->text("Email: $testEmail");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch email settings: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function testEmail(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $to = $this->getStringOption($input, 'to');
        if ($to === null || $to === '') {
            $this->io()->error('Recipient email is required');
            $this->io()->text('Usage: kvs email test --to=recipient@example.com');
            return self::FAILURE;
        }

        // Validate email format
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->io()->error('Invalid email address format');
            return self::FAILURE;
        }

        $subject = $this->getStringOption($input, 'subject') ?? 'KVS CLI Test Email';
        $body = $this->getStringOption($input, 'body') ?? 'This is a test email sent from KVS CLI at ' . date('Y-m-d H:i:s');

        try {
            // Get current email settings
            $stmt = $db->prepare("
                SELECT value FROM {$this->table('settings')}
                WHERE section = 'email'
            ");
            $stmt->execute();
            $value = $stmt->fetchColumn();

            if ($value === false || !is_string($value)) {
                $this->io()->error('Email settings not configured');
                return self::FAILURE;
            }

            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                $this->io()->error('Invalid email settings format');
                return self::FAILURE;
            }
            /** @var array<string, mixed> $settings */
            $settings = $decoded;

            $mailer = $this->getSettingString($settings, 'use_mailer', 'php');
            $fromEmail = $this->getSettingString($settings, 'send_from_email', '');
            $fromName = $this->getSettingString($settings, 'send_from_title', '');

            if ($fromEmail === '') {
                $this->io()->error('From email address not configured');
                $this->io()->text('Set it with: kvs email set --from-email=your@email.com');
                return self::FAILURE;
            }

            $this->io()->text('Sending test email to: ' . $to);
            $this->io()->text('From: ' . $fromEmail . ($fromName !== '' ? " ($fromName)" : ''));
            $this->io()->text('Subject: ' . $subject);
            $this->io()->text('Mailer: ' . $mailer);
            $this->io()->newLine();

            // Build headers
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/plain; charset=UTF-8';
            if ($fromName !== '') {
                $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
            } else {
                $headers[] = 'From: ' . $fromEmail;
            }

            if ($mailer === 'php') {
                // Use PHP mail()
                $result = @mail($to, $subject, $body, implode("\r\n", $headers));

                if ($result) {
                    $this->io()->success('Test email sent successfully!');
                    $this->io()->note('Check your inbox (and spam folder) for the test email.');
                } else {
                    $this->io()->error('Failed to send email via PHP mail()');
                    $this->io()->text('Check your server mail configuration.');
                    return self::FAILURE;
                }
            } elseif ($mailer === 'smtp') {
                $this->io()->warning('SMTP sending requires KVS internal API');
                $this->io()->text('Use the admin panel to send test emails via SMTP.');
                $this->io()->text('Or configure PHP mail() as fallback: kvs email set --mailer=php');
                return self::FAILURE;
            } else {
                $this->io()->warning('Custom mailer \'' . $mailer . '\' not supported via CLI');
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to send test email: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function setSettings(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get current settings
            $stmt = $db->prepare("
                SELECT value FROM {$this->table('settings')}
                WHERE section = 'email'
            ");
            $stmt->execute();
            $value = $stmt->fetchColumn();

            /** @var array<string, mixed> $settings */
            $settings = [];
            if ($value !== false && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $settings */
                    $settings = $decoded;
                }
            }

            // Track changes
            $changes = [];

            // Update settings from options
            $mailer = $this->getStringOption($input, 'mailer');
            if ($mailer !== null) {
                if (!in_array($mailer, ['php', 'smtp', 'custom'], true)) {
                    $this->io()->error("Invalid mailer type: $mailer (use: php, smtp, custom)");
                    return self::FAILURE;
                }
                $settings['use_mailer'] = $mailer;
                $changes[] = "Mailer: $mailer";
            }

            $fromEmail = $this->getStringOption($input, 'from-email');
            if ($fromEmail !== null) {
                if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
                    $this->io()->error('Invalid from email address format');
                    return self::FAILURE;
                }
                $settings['send_from_email'] = $fromEmail;
                $changes[] = "From email: $fromEmail";
            }

            $fromName = $this->getStringOption($input, 'from-name');
            if ($fromName !== null) {
                $settings['send_from_title'] = $fromName;
                $changes[] = "From name: $fromName";
            }

            $smtpHost = $this->getStringOption($input, 'smtp-host');
            if ($smtpHost !== null) {
                $settings['smtp_host'] = $smtpHost;
                $changes[] = "SMTP host: $smtpHost";
            }

            $smtpPort = $this->getStringOption($input, 'smtp-port');
            if ($smtpPort !== null) {
                $port = (int) $smtpPort;
                if ($port < 1 || $port > 65535) {
                    $this->io()->error('Invalid SMTP port (1-65535)');
                    return self::FAILURE;
                }
                $settings['smtp_port'] = $port;
                $changes[] = "SMTP port: $port";
            }

            $smtpUser = $this->getStringOption($input, 'smtp-user');
            if ($smtpUser !== null) {
                $settings['smtp_username'] = $smtpUser;
                $changes[] = "SMTP username: $smtpUser";
            }

            $smtpPass = $this->getStringOption($input, 'smtp-pass');
            if ($smtpPass !== null) {
                $settings['smtp_password'] = $smtpPass;
                $changes[] = 'SMTP password: ********';
            }

            $smtpSecurity = $this->getStringOption($input, 'smtp-security');
            if ($smtpSecurity !== null) {
                if (!in_array($smtpSecurity, ['tls', 'ssl'], true)) {
                    $this->io()->error("Invalid SMTP security: $smtpSecurity (use: tls, ssl)");
                    return self::FAILURE;
                }
                $settings['smtp_security'] = $smtpSecurity;
                $changes[] = "SMTP security: $smtpSecurity";
            }

            if ($changes === []) {
                $this->io()->warning('No settings to update. Use options like --mailer, --from-email, etc.');
                return self::SUCCESS;
            }

            // Save settings
            $newValue = json_encode($settings);
            if ($newValue === false) {
                $this->io()->error('Failed to encode settings');
                return self::FAILURE;
            }

            $stmt = $db->prepare("
                UPDATE {$this->table('settings')}
                SET value = :value
                WHERE section = 'email'
            ");
            $stmt->execute(['value' => $newValue]);

            $this->io()->success('Email settings updated:');
            foreach ($changes as $change) {
                $this->io()->text("  • $change");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to update settings: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function formatDebugLevel(string $level): string
    {
        return match ($level) {
            '0' => 'None',
            '1' => 'Basic',
            '2' => 'Extended',
            default => "Unknown ($level)",
        };
    }

    private function maskPassword(string $password): string
    {
        if ($password === '') {
            return '<not set>';
        }
        $len = strlen($password);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($password, 0, 2) . str_repeat('*', $len - 4) . substr($password, -2);
    }

    /**
     * Get string value from settings array.
     *
     * @param array<string, mixed> $settings
     */
    private function getSettingString(array $settings, string $key, string $default = ''): string
    {
        $value = $settings[$key] ?? $default;
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : $default);
    }

    /**
     * Get integer value from settings array.
     *
     * @param array<string, mixed> $settings
     */
    private function getSettingInt(array $settings, string $key, int $default = 0): int
    {
        $value = $settings[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}
