<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Service\StorageClusterDataPublisher;
use KVS\CLI\Service\StorageServerWeightException;
use KVS\CLI\Service\StorageServerWeightManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class ServerWeightMariaDbIntegrationTest extends TestCase
{
    public function testMyIsamLockingPublicationAndCompensatingRollback(): void
    {
        if (getenv('KVS_CLI_TEST_SERVER_WEIGHTS_MARIADB') !== '1') {
            $this->markTestSkipped('Set KVS_CLI_TEST_SERVER_WEIGHTS_MARIADB=1 to run this MariaDB contract test.');
        }

        $config = TestHelper::getDbConfig();
        $db = $this->connect($config);
        $secondConnection = $this->connect($config);
        $prefix = 'kvs_cli_weights_' . bin2hex(random_bytes(5)) . '_';
        $serverTable = $prefix . 'admin_servers';
        $groupTable = $prefix . 'admin_servers_groups';
        $temporaryDirectory = TestHelper::createTempDir('server-weights-mariadb-');
        $clusterFile = $temporaryDirectory . '/cluster.dat';

        try {
            $this->createSchema($db, $serverTable, $groupTable);
            $publisher = new StorageClusterDataPublisher($clusterFile);
            $clusterRows = $publisher->fetchRows($db, $serverTable);
            file_put_contents($clusterFile, serialize($clusterRows));
            chmod($clusterFile, 0640);
            $manager = new StorageServerWeightManager(
                $db,
                $serverTable,
                $groupTable,
                $config['database'],
                $publisher,
                1
            );

            $engine = $db->query(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = " . $db->quote($serverTable)
            );
            $this->assertNotFalse($engine);
            $this->assertSame('MyISAM', $engine->fetchColumn());

            $initial = $manager->read(3);
            $weights = [12 => 4, 13 => 2, 14 => 1, 15 => 3, 16 => 1];
            $result = $manager->apply(3, $weights, $initial['revision'], false, false);

            $this->assertTrue($result['changed']);
            $this->assertTrue($result['cluster_data_updated']);
            $this->assertSame($weights, $this->fetchWeights($db, $serverTable));
            $this->assertSame(0640, fileperms($clusterFile) & 0777);
            $publishedRows = $publisher->readRows();
            $this->assertSame([12, 13, 14, 15, 16], array_map('intval', array_column($publishedRows, 'server_id')));
            foreach ($publishedRows as $row) {
                $this->assertSame(StorageClusterDataPublisher::FIELDS, array_keys($row));
            }

            $lockName = $this->lockName($config['database'], $serverTable);
            $lock = $secondConnection->prepare('SELECT GET_LOCK(:lock_name, 0)');
            $lock->execute(['lock_name' => $lockName]);
            $this->assertSame(1, (int) $lock->fetchColumn());
            try {
                $manager->apply(3, $weights, $result['revision_after'], false, true);
                $this->fail('The second connection should prevent the manager from acquiring its advisory lock.');
            } catch (StorageServerWeightException $e) {
                $this->assertSame('lock_unavailable', $e->getErrorCode());
            } finally {
                $release = $secondConnection->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
            }

            $stableBytes = file_get_contents($clusterFile);
            $this->assertIsString($stableBytes);
            $failingPublisher = new class ($clusterFile) extends StorageClusterDataPublisher {
                private bool $failNextVerification = true;

                public function readRows(): array
                {
                    if ($this->failNextVerification) {
                        $this->failNextVerification = false;
                        throw new \RuntimeException('Forced MariaDB final verification failure');
                    }

                    return parent::readRows();
                }
            };
            $failingManager = new StorageServerWeightManager(
                $db,
                $serverTable,
                $groupTable,
                $config['database'],
                $failingPublisher,
                1
            );
            $replacement = [12 => 6, 13 => 1, 14 => 2, 15 => 4, 16 => 1];
            try {
                $failingManager->apply(3, $replacement, $result['revision_after'], false, false);
                $this->fail('Final verification should have failed.');
            } catch (StorageServerWeightException $e) {
                $this->assertSame('set_weights_failed', $e->getErrorCode());
                $this->assertFalse($e->isRecoveryRequired());
            }

            $this->assertSame($weights, $this->fetchWeights($db, $serverTable));
            $this->assertSame($stableBytes, file_get_contents($clusterFile));
        } finally {
            $secondConnection = null;
            $db->exec("DROP TABLE IF EXISTS {$serverTable}, {$groupTable}");
            TestHelper::removeDir($temporaryDirectory);
        }
    }

    /**
     * @param array{host: string, port: int, user: string, pass: string, database: string} $config
     */
    private function connect(array $config): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]
        );
    }

    private function createSchema(PDO $db, string $serverTable, string $groupTable): void
    {
        $db->exec(
            "CREATE TABLE {$groupTable} (group_id INT UNSIGNED NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL) "
            . 'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4'
        );
        $db->exec(
            "CREATE TABLE {$serverTable} ("
            . 'server_id INT UNSIGNED NOT NULL PRIMARY KEY, group_id INT UNSIGNED NOT NULL, '
            . 'content_type_id TINYINT UNSIGNED NOT NULL, status_id TINYINT UNSIGNED NOT NULL, '
            . 'streaming_type_id TINYINT UNSIGNED NOT NULL, streaming_script TEXT NOT NULL, '
            . 'streaming_key VARCHAR(255) NOT NULL, is_replace_domain_on_satellite TINYINT UNSIGNED NOT NULL, '
            . 'urls TEXT NOT NULL, is_remote TINYINT UNSIGNED NOT NULL, control_script_url TEXT NOT NULL, '
            . 'control_script_url_lock_ip TINYINT UNSIGNED NOT NULL, time_offset DECIMAL(10,2) NOT NULL, '
            . 'lb_weight DECIMAL(10,2) NOT NULL, lb_countries VARCHAR(255) NOT NULL, '
            . 'error_streaming_id INT UNSIGNED NOT NULL, error_streaming_iteration INT UNSIGNED NOT NULL, '
            . 'warning_id INT UNSIGNED NOT NULL, error_iteration INT UNSIGNED NOT NULL'
            . ') ENGINE=MyISAM DEFAULT CHARSET=utf8mb4'
        );
        $db->exec("INSERT INTO {$groupTable} VALUES (3, 'Video storage')");
        $insert = $db->prepare(
            "INSERT INTO {$serverTable} (server_id, group_id, content_type_id, status_id, streaming_type_id, "
            . 'streaming_script, streaming_key, is_replace_domain_on_satellite, urls, is_remote, '
            . 'control_script_url, control_script_url_lock_ip, time_offset, lb_weight, lb_countries, '
            . 'error_streaming_id, error_streaming_iteration, warning_id, error_iteration) '
            . 'VALUES (?, 3, 1, ?, ?, ?, ?, 0, ?, 0, ?, 0, 0, ?, ?, 0, ?, 0, ?)'
        );
        $rows = [
            [12, 1, 4, '', 'secret-unrestricted', 'https://all.example.test', '', '1.50', '', 21, 31],
            [13, 1, 4, '', 'secret-ca', 'https://ca.example.test', '', '2', 'CA', 22, 32],
            [14, 0, 4, '', 'secret-disabled', 'https://disabled.example.test', '', '1', '', 23, 33],
            [15, 1, 5, '', 'secret-backup', '', '', '1', '', 24, 34],
            [16, 1, 4, '', 'secret-us', 'https://us.example.test', '', '3', 'US', 25, 35],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }
    }

    /**
     * @return array<int, int>
     */
    private function fetchWeights(PDO $db, string $serverTable): array
    {
        $stmt = $db->query("SELECT server_id, lb_weight FROM {$serverTable} ORDER BY server_id");
        $this->assertNotFalse($stmt);
        $weights = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $weights[(int) $row['server_id']] = (int) $row['lb_weight'];
        }

        return $weights;
    }

    private function lockName(string $database, string $serverTable): string
    {
        return 'kvs_cli_weights_' . substr(hash('sha256', $database . '|' . $serverTable), 0, 40);
    }
}
