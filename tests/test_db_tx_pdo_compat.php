<?php
/** Regression for PDO SQLite builds that hide raw BEGIN IMMEDIATE state. */
declare(strict_types=1);

/* Some server PDO SQLite drivers return false from inTransaction() after a
   transaction opened with exec('BEGIN IMMEDIATE'). Simulate that exact driver
   quirk rather than assuming the local PHP implementation behaves the same. */
final class TxCompatLyingPdo extends PDO
{
    public function inTransaction(): bool
    {
        return false;
    }
}

require __DIR__ . '/../sibil.php';

$pdo = new TxCompatLyingPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$property = new ReflectionProperty(DB::class, 'pdo');
$property->setValue(null, $pdo);

$ok = false;
try {
    $pdo->exec('CREATE TABLE tx_fixture (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    DB::tx(static function (): void {
        DB::tx(static function (): void {
            DB::exec("INSERT INTO tx_fixture(value) VALUES('nested-ok')");
        });
    });
    $ok = (int) $pdo->query('SELECT COUNT(*) FROM tx_fixture')->fetchColumn() === 1;
} catch (Throwable) {
    $ok = false;
} finally {
    DB::close();
}

printf("%s - DB transaction nesting survives PDO SQLite inTransaction compatibility behavior\n", $ok ? 'ok' : 'not ok');
exit($ok ? 0 : 1);
