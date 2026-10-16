<?php
declare(strict_types=1);
namespace Fpanel\Database;

use PDO;
use Fpanel\Support\Config;
use RuntimeException;

/** Single SQLite connection: WAL + foreign keys ON. */
final class Connection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $file = Config::get('db.file');
        if (!is_string($file) || $file === '') {
            throw new RuntimeException('Config db.file is not set');
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create the DB directory: $dir");
        }
        self::$pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(static fn($c) => ':' . $c, $cols);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', $ph)
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = array_map(static fn($c) => "$c = :$c", array_keys($data));
        $sql  = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(',', $sets), $where);
        return self::run($sql, $data + $whereParams)->rowCount();
    }

    /** Used by tests: wipe all data then run the schema again. */
    public static function reset(): void
    {
        self::$pdo = null;
        $file = Config::get('db.file');
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }
}
