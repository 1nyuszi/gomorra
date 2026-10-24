<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class MySqlConnectionFactory
{
    public static function create(string $dsn, string $user, string $password): PDO
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('DATABASE_DSN must use PDO MySQL.');
        }

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }
}
