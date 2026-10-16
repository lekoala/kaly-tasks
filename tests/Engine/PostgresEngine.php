<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

use Closure;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\DatabaseManager;
use Doctrine\DBAL\DriverManager;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\CycleConnection;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Store\TaskStore;
use PDO;

/**
 * A real PostgreSQL: KALY_TASKS_PGSQL_DSN, see compose.yaml.
 */
final readonly class PostgresEngine implements Engine
{
    public function __construct(
        public string $dsn,
    ) {}

    /**
     * @return self|null Null when KALY_TASKS_PGSQL_DSN is empty
     */
    public static function fromEnvironment(): ?self
    {
        $dsn = getenv('KALY_TASKS_PGSQL_DSN');
        return is_string($dsn) && $dsn !== '' ? new self($dsn) : null;
    }

    public function connect(): PDO
    {
        return new PDO($this->dsn);
    }

    public function store(Connection $connection, string $table): TaskStore
    {
        return new PostgresTaskStore($connection, $table);
    }

    public function reset(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        foreach ((new PostgresTaskStore(new PdoConnection($pdo), $table))->schema() as $statement) {
            $pdo->exec($statement);
        }
    }

    public function age(PDO $pdo, string $table, int $seconds): void
    {
        $pdo->exec(sprintf("UPDATE %s SET lease_until = lease_until - INTERVAL '%2\$d seconds', available_at = available_at - INTERVAL '%2\$d seconds',
            finished_at = finished_at - INTERVAL '%2\$d seconds'", $table, $seconds));
    }

    public function arguments(): array
    {
        return ['pgsql', $this->dsn];
    }

    public function cleanUp(): void {}

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    public function viaPdo(): array
    {
        $pdo = $this->connect();
        return [
            'connection' => new PdoConnection(static fn(): PDO => $pdo),
            'begin' => $pdo->beginTransaction(...),
            'commit' => $pdo->commit(...),
            'rollback' => $pdo->rollBack(...),
        ];
    }

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    public function viaDoctrine(): array
    {
        $dbal = DriverManager::getConnection(['driver' => 'pdo_pgsql', ...$this->doctrineParams()]);
        return [
            'connection' => new DoctrineConnection($dbal),
            'begin' => $dbal->beginTransaction(...),
            'commit' => $dbal->commit(...),
            'rollback' => $dbal->rollBack(...),
        ];
    }

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    public function viaCycle(): array
    {
        $dsn = $this->dsnParts();
        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'pgsql']],
            'connections' => [
                'pgsql' => new PostgresDriverConfig(new TcpConnectionConfig(
                    database: $dsn['dbname'],
                    host: $dsn['host'],
                    port: $dsn['port'],
                    user: $dsn['user'],
                    password: $dsn['password'],
                )),
            ],
        ]));
        $database = $manager->database();
        return [
            'connection' => new CycleConnection($database),
            'begin' => $database->begin(...),
            'commit' => $database->commit(...),
            'rollback' => $database->rollback(...),
        ];
    }

    /**
     * @return array{host:non-empty-string,port:int,dbname:non-empty-string,user:non-empty-string,password:non-empty-string}
     */
    public function doctrineParams(): array
    {
        $dsn = $this->dsnParts();
        return [
            'host' => $dsn['host'],
            'port' => (int) $dsn['port'],
            'dbname' => $dsn['dbname'],
            'user' => $dsn['user'],
            'password' => $dsn['password'],
        ];
    }

    /**
     * @return array{host:non-empty-string,port:numeric-string,dbname:non-empty-string,user:non-empty-string,password:non-empty-string}
     */
    private function dsnParts(): array
    {
        $parts = ['host' => 'localhost', 'port' => '5432', 'dbname' => '', 'user' => '', 'password' => ''];
        foreach (explode(';', substr($this->dsn, strlen('pgsql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[$key] = $value;
        }
        /** @var array{host:non-empty-string,port:numeric-string,dbname:non-empty-string,user:non-empty-string,password:non-empty-string} */
        return $parts;
    }
}
