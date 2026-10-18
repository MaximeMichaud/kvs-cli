<?php

namespace KVS\CLI\Tests\Support;

final class MigrationDatabaseFixture
{
    public const SQL = "CREATE TABLE fixture_data(id int);\nINSERT INTO fixture_data VALUES (1);\n";
    public const PASSWORD = 'fixture-only-password';

    public static function create(string $root): string
    {
        $tools = $root . '/tools';
        mkdir($tools, 0755, true);
        $docker = <<<'SH'
#!/bin/sh
[ "$1" = exec ] || exit 24
shift
if [ "$1" = -i ]; then shift; fi
[ "$1" = fixture-mariadb ] || exit 25
shift
MARIADB_ROOT_PASSWORD=fixture-only-password MYSQL_PWD= exec "$@"
SH;
        $client = "#!/bin/sh\n"
            . 'if [ "$MYSQL_PWD" = fixture-only-password ]; then auth=true; else auth=false; fi' . "\n"
            . 'printf "%s\n" "$auth" >> ' . escapeshellarg($root . '/auth.log') . "\n"
            . 'printf "%s\n" "$*" >> ' . escapeshellarg($root . '/args.log') . "\n"
            // Let the missing-credential regression reach the import without readiness retries.
            . 'case "$*" in *"-e SELECT 1"*) printf "1\n"; exit 0 ;; esac' . "\n"
            . 'if [ "$auth" != true ]; then echo "Access denied" >&2; exit 23; fi' . "\n"
            . 'if [ -f ' . escapeshellarg($root . '/reject-import') . ' ]; then echo "SQL rejected" >&2; exit 26; fi' . "\n"
            . 'cat > ' . escapeshellarg($root . '/captured.sql') . "\n";
        $decompressor = <<<'SH'
#!/bin/sh
out=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = -o ]; then shift; out="$1"; fi
    shift
done
SH;
        $decompressor .= "\nprintf '%s' " . escapeshellarg(self::SQL) . ' > "$out"' . "\n";
        foreach (['docker' => $docker, 'mariadb' => $client, 'zstd' => $decompressor] as $name => $script) {
            file_put_contents($tools . '/' . $name, $script);
            chmod($tools . '/' . $name, 0755);
        }
        return $tools;
    }
}
