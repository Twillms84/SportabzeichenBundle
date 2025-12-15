<?php

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\DBAL\Connection;

return static function (Connection $connection): Configuration {
    $config = new Configuration($connection);

    $config->setName('Sportabzeichen Migrations');
    $config->setMigrationsNamespace('PulsR\\SportabzeichenBundle\\Migrations');
    $config->setMigrationsDirectory(__DIR__ . '/../Migrations');
    $config->setMigrationsTableName('sportabzeichen_migration_versions');

    return $config;
};
j
