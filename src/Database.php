<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $databasePath): PDO
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stock_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                quantity INTEGER NOT NULL CHECK (quantity > 0),
                stock_date TEXT NOT NULL,
                note TEXT DEFAULT "",
                created_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL CHECK (type IN ("sale", "income", "expense")),
                amount REAL NOT NULL CHECK (amount > 0),
                quantity INTEGER,
                description TEXT DEFAULT "",
                transaction_date TEXT NOT NULL,
                created_at TEXT NOT NULL,
                source_role TEXT NOT NULL
            )'
        );
    }
}
