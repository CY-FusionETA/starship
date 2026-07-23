<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

/** Thin PDO wrapper — lazy singleton connection + small query helpers. */
final class Db
{
    private static ?PDO $pdo = null;

    /**
     * Training Mode: when on, every query runs against a SEPARATE demo database
     * (storage/starship_demo.sqlite) instead of the real one. The guided tour
     * flips this so a learner's practice MRs/POs/DOs never touch live data.
     * Toggling drops the current connection so the next conn() reopens the
     * right file.
     */
    private static bool $demo = false;

    public static function useDemo(bool $on): void
    {
        if ($on !== self::$demo) self::$pdo = null;
        self::$demo = $on;
    }

    public static function isDemo(): bool { return self::$demo; }

    /** Absolute path of the demo database file. */
    public static function demoPath(): string { return STORAGE_ROOT . '/starship_demo.sqlite'; }

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            // SQLite: a single self-contained file inside the protected storage/ dir.
            $path = self::$demo
                ? self::demoPath()
                : (cfg('db.path') ?: (STORAGE_ROOT . '/starship.sqlite'));
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0770, true);
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');   // safe concurrent reads during writes
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');  // wait out a concurrent writer
        }
        return self::$pdo;
    }

    /** Run a prepared statement, return the statement. */
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** First row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    /** Single scalar value. */
    public static function scalar(string $sql, array $params = [])
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert row from assoc array, return new id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        self::q($sql, $data);
        return (int)self::conn()->lastInsertId();
    }

    /** Update by id, return affected rows. */
    public static function update(string $table, int $id, array $data): int
    {
        $set = implode(',', array_map(fn($c) => "{$c}=:{$c}", array_keys($data)));
        $data['__id'] = $id;
        return self::q("UPDATE {$table} SET {$set} WHERE id=:__id", $data)->rowCount();
    }

    /** Run a callable inside a transaction. */
    public static function tx(callable $fn)
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    }
}
