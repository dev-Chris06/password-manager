<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the session handler on top of the Doctrine connection.
 *
 * Advisory locks (GET_LOCK) are used instead of transactional locks: a transaction held for
 * the whole request would collide with the transactions opened by Doctrine on the same connection.
 */
final class DatabaseSessionHandlerFactory
{
    public const string TABLE = 'sessions';

    public static function create(Connection $connection): PdoSessionHandler
    {
        $pdo = $connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Database sessions require a PDO-based Doctrine driver (pdo_mysql).');
        }

        return new PdoSessionHandler($pdo, [
            'db_table' => self::TABLE,
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
