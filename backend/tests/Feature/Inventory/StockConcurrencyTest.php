<?php

/**
 * section 13.1: "two concurrent decrements of the last unit cannot both
 * succeed." PHP is single-threaded and the default test connection is an
 * in-memory SQLite database that is not even shared across two
 * connections, so real concurrency can't be simulated by running two
 * things "at once." What CAN be proven, directly against the real engine
 * StockService::lockOrCreateBalance relies on, is that its
 * `lockForUpdate()` genuinely creates mutual exclusion: this test opens
 * transaction A and locks a stock_balances row, then - without committing
 * A - opens a SECOND, independent MySQL connection and attempts the same
 * lock with a one-second wait timeout. If locking were a no-op, B's lock
 * would succeed immediately; instead it must time out, proving the row is
 * genuinely held. Only once A releases does a fresh attempt succeed.
 *
 * Runs against a real MySQL database (not the sqlite test connection,
 * which phpunit.xml points at :memory: - SQLite's locking model is
 * file/database-level, not row-level, and would not exercise the same
 * code path StockService uses in production - InnoDB row locks) via raw
 * PDO against whatever database STOCK_LOCK_TEST_DB names (defaults to the
 * local dev database `common_pos`; CI points it at the mysql service's
 * `common_pos_test`, already migrated by an earlier CI step).
 */
test('a locked stock_balances row genuinely blocks a second transaction, proving the race StockService relies on cannot occur', function () {
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname='.(getenv('STOCK_LOCK_TEST_DB') ?: 'common_pos');
    $user = env('DB_USERNAME') ?: 'root';
    $pass = env('DB_PASSWORD', config('database.connections.mysql.password', 'root'));
    $pdo1 = new PDO($dsn, $user, $pass);
    $pdo1->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo1->exec("INSERT INTO stock_balances (tenant_id, warehouse_id, product_id, variant_key, batch_key, quantity, average_cost, created_at, updated_at) VALUES (999999, 999999, 999999, 0, 0, '1.0000', '10.0000', NOW(), NOW())");
    $balanceId = (int) $pdo1->lastInsertId();
    $pdo1->exec('SET FOREIGN_KEY_CHECKS=1');

    try {
        $pdo1->beginTransaction();
        $pdo1->query("SELECT * FROM stock_balances WHERE id = {$balanceId} FOR UPDATE")->fetch();

        $pdo2 = new PDO($dsn, $user, $pass);
        $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo2->beginTransaction();

        $secondTransactionTimedOut = false;
        try {
            $pdo2->query("SELECT * FROM stock_balances WHERE id = {$balanceId} FOR UPDATE")->fetch();
        } catch (PDOException $e) {
            $secondTransactionTimedOut = true;
        }
        $pdo2->rollBack();

        expect($secondTransactionTimedOut)->toBeTrue();

        $pdo1->commit();

        // Now that A released the lock, a fresh attempt succeeds immediately.
        $pdo3 = new PDO($dsn, $user, $pass);
        $pdo3->beginTransaction();
        $row = $pdo3->query("SELECT * FROM stock_balances WHERE id = {$balanceId} FOR UPDATE")->fetch();
        expect($row)->not->toBeFalse();
        $pdo3->rollBack();
    } finally {
        $pdo1->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo1->exec("DELETE FROM stock_balances WHERE id = {$balanceId}");
        $pdo1->exec('SET FOREIGN_KEY_CHECKS=1');
    }
})->group('mysql-integration');
