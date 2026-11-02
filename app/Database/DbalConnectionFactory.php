<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;

/**
 * Connessione doctrine/dbal per i componenti Symfony (Lock DoctrineDbalStore, trasporto
 * Doctrine di Messenger). Stessi parametri di App\Database\Connection (Connection::params()),
 * connessione separata dal PDO applicativo (DBAL 4 non accetta un PDO esistente).
 * Nessun ORM: le tabelle applicative restano su PDO.
 */
final class DbalConnectionFactory
{
    private static ?DbalConnection $shared = null;

    /** Connessione condivisa per processo (lazy: DBAL apre il socket alla prima query). */
    public static function get(): DbalConnection
    {
        return self::$shared ??= self::create();
    }

    public static function create(): DbalConnection
    {
        $p = Connection::params();

        $driverOptions = [];
        // Stessa collation di sessione del PDO applicativo (Connection::connect()).
        if (class_exists(\Pdo\Mysql::class)) {
            $driverOptions[\Pdo\Mysql::ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        $config = new Configuration();
        $config->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

        return DriverManager::getConnection([
            'driver'        => 'pdo_mysql',
            'host'          => $p['host'],
            'port'          => (int)$p['port'],
            'dbname'        => $p['dbname'],
            'user'          => $p['user'],
            'password'      => $p['password'],
            'charset'       => 'utf8mb4',
            'driverOptions' => $driverOptions,
        ], $config);
    }
}
