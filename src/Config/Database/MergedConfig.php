<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\Config\Database;

use Magento\MagentoCloud\Config\ConfigMerger;
use Magento\MagentoCloud\Config\Deploy\Reader as ConfigReader;
use Magento\MagentoCloud\Config\Stage\DeployInterface;
use Magento\MagentoCloud\DB\Data\ConnectionInterface;
use Magento\MagentoCloud\DB\Data\RelationshipConnectionFactory;

/**
 * Returns merged final database configuration.
 */
class MergedConfig implements ConfigInterface
{
    const KEY_CONNECTION = 'connection';
    const KEY_SLAVE_CONNECTION = 'slave_connection';
    const KEY_RESOURCE = 'resource';
    const KEY_DB = 'db';

    const CONNECTION_DEFAULT = 'default';
    const CONNECTION_INDEXER = 'indexer';
    const CONNECTION_CHECKOUT = 'checkout';
    const CONNECTION_SALES = 'sales';

    const RESOURCE_CHECKOUT = 'checkout';
    const RESOURCE_SALES = 'sales';
    const RESOURCE_DEFAULT_SETUP = 'default_setup';

    const CONNECTION_MAP = [
        self::CONNECTION_DEFAULT => [
            self::KEY_CONNECTION => RelationshipConnectionFactory::CONNECTION_MAIN,
            self::KEY_SLAVE_CONNECTION => RelationshipConnectionFactory::CONNECTION_SLAVE,
        ],
        self::CONNECTION_INDEXER => [
            self::KEY_CONNECTION => RelationshipConnectionFactory::CONNECTION_MAIN,
        ],
        self::CONNECTION_CHECKOUT => [
            self::KEY_CONNECTION => RelationshipConnectionFactory::CONNECTION_QUOTE_MAIN,
            self::KEY_SLAVE_CONNECTION => RelationshipConnectionFactory::CONNECTION_QUOTE_SLAVE,
        ],
        self::CONNECTION_SALES => [
            self::KEY_CONNECTION => RelationshipConnectionFactory::CONNECTION_SALES_MAIN,
            self::KEY_SLAVE_CONNECTION => RelationshipConnectionFactory::CONNECTION_SALES_SLAVE,
        ]
    ];

    const RESOURCE_MAP = [
        self::CONNECTION_DEFAULT => self::RESOURCE_DEFAULT_SETUP,
        self::CONNECTION_CHECKOUT => self::RESOURCE_CHECKOUT,
        self::CONNECTION_SALES => self::RESOURCE_SALES,
    ];

    const REQUIRED_CONNECTION = [self::CONNECTION_DEFAULT, self::CONNECTION_INDEXER];

    /**
     * Final configuration for deploy phase
     *
     * @var DeployInterface
     */
    private $stageConfig;

    /**
     * Class for configuration merging
     *
     * @var ConfigMerger
     */
    private $configMerger;

    /**
     * Connection data from relationship array
     *
     * @var array
     */
    private $connectionData;

    /**
     * Reader for app/etc/env.php file
     *
     * @var ConfigReader
     */
    private $configReader;

    /**
     * Factory for creation database configurations
     *
     * @var RelationshipConnectionFactory
     */
    private $connectionDataFactory;

    /**
     * Database configuration from app/etc/env.php file
     *
     * @var array
     */
    private $dbConfigFromEnvFile;

    /**
     * Final database configuration after merging
     *
     * @var array
     */
    private $mergedConfig;

    /**
     * @param ConfigReader $configReader
     * @param ConfigMerger $configMerger
     * @param DeployInterface $stageConfig
     * @param RelationshipConnectionFactory $connectionDataFactory
     */
    public function __construct(
        ConfigReader $configReader,
        ConfigMerger $configMerger,
        DeployInterface $stageConfig,
        RelationshipConnectionFactory $connectionDataFactory
    ) {
        $this->connectionDataFactory = $connectionDataFactory;
        $this->configReader = $configReader;
        $this->stageConfig = $stageConfig;
        $this->configMerger = $configMerger;
    }

    /**
     * Returns database and resource configurations
     *
     * Returns
     * ```
     * [
     *     'db' => [...]       // Database configuration
     *     'resource' => [...] // Resource configuration
     * ]
     *
     * ```
     *
     * @return array
     */
    public function get(): array
    {
        if ($this->mergedConfig !== null) {
            return $this->mergedConfig;
        }

        $dbConfig = $this->getDatabaseConfig();
        $connections = array_keys($dbConfig[self::KEY_CONNECTION]);
        return $this->mergedConfig = [
            self::KEY_DB => $dbConfig,
            self::KEY_RESOURCE => $this->getResourceConfig($connections),
        ];
    }

    /**
     * Returns database configuration
     *
     * @return array
     */
    private function getDatabaseConfig(): array
    {
        $envConfig = $this->stageConfig->get(DeployInterface::VAR_DATABASE_CONFIGURATION);

        if (!$this->configMerger->isEmpty($envConfig) && !$this->configMerger->isMergeRequired($envConfig)) {
            return $this->configMerger->clear($envConfig);
        }

        $useSlave = $this->stageConfig->get(DeployInterface::VAR_MYSQL_USE_SLAVE_CONNECTION);

        $config = [];
        foreach (self::CONNECTION_MAP as $connName => $connConfig) {
            $connData = $this->getConnectionData($connConfig[self::KEY_CONNECTION]);
            if (!$this->checkConnectionData($connData, $connName)) {
                continue;
            }
            $config[self::KEY_CONNECTION][$connName] = $this->getConnectionConfig($connData);
            if (!$useSlave || !isset($connConfig[self::KEY_SLAVE_CONNECTION])) {
                continue;
            }
            $connData = $this->getConnectionData($connConfig[self::KEY_SLAVE_CONNECTION]);
            if (!$this->checkSlaveConnectionData($connData, $connName, $config)) {
                continue;
            }
            $config[self::KEY_SLAVE_CONNECTION][$connName] = $this->getConnectionConfig($connData, true);
        }

        if (empty($config)) {
            $config = $this->getDbConfigFromEnvFile();
        }

        return $this->configMerger->merge($config, $envConfig);
    }

    /**
     * Returns resource configuration
     *
     * @param array $connections
     * @return array
     */
    private function getResourceConfig(array $connections): array
    {
        $envConfig = $this->stageConfig->get(DeployInterface::VAR_RESOURCE_CONFIGURATION);

        if (!$this->configMerger->isEmpty($envConfig) && !$this->configMerger->isMergeRequired($envConfig)) {
            return $this->configMerger->clear($envConfig);
        }

        $config = [];
        foreach ($connections as $connection) {
            if (isset(self::RESOURCE_MAP[$connection])) {
                $config[self::RESOURCE_MAP[$connection]][self::KEY_CONNECTION] = $connection;
            }
        }

        return $this->configMerger->merge($config, $envConfig);
    }

    /**
     * Checks connection data
     *
     * @param ConnectionInterface $connectionData
     * @param string $type
     * @return bool
     */
    private function checkConnectionData(ConnectionInterface $connectionData, string $type): bool
    {
        return !empty($connectionData->getHost())
            && (in_array($type, self::REQUIRED_CONNECTION)
                || isset($this->getDbConfigFromEnvFile()[self::KEY_CONNECTION][$type]));
    }

    /**
     * Checks slave connection data
     *
     * @param ConnectionInterface $connectionData
     * @param string $type
     * @param array $dbConfig
     * @return bool
     */
    private function checkSlaveConnectionData(
        ConnectionInterface $connectionData,
        string $type,
        array $dbConfig
    ): bool {
        $envConfig = $this->getDbConfigFromEnvFile();
        return !empty($connectionData->getHost())
            && $this->isDbConfigCompatibleWithSlaveConnection($type)
            && isset($dbConfig[self::KEY_CONNECTION][$type])
            && (in_array($type, self::REQUIRED_CONNECTION)
                || (isset($envConfig[self::KEY_CONNECTION][$type])
                    && isset($envConfig[self::KEY_SLAVE_CONNECTION][$type])));
    }

    /**
     * Checks that database configuration was changed in DATABASE_CONFIGURATION variable
     * in not compatible way with slave_connection.
     *
     * Returns true if $envDbConfig contains host or dbname for default connection
     * that doesn't match connection from relationships,
     * otherwise return false.
     *
     * @param string $connection
     * @return boolean
     */
    public function isDbConfigCompatibleWithSlaveConnection(string $connection): bool
    {
        $config = $this->stageConfig->get(DeployInterface::VAR_DATABASE_CONFIGURATION);
        $connectionData = $this->getConnectionData(self::CONNECTION_MAP[$connection][self::KEY_CONNECTION]);
        if ((isset($config[self::KEY_CONNECTION][$connection]['host'])
                && $config[self::KEY_CONNECTION][$connection]['host'] !== $connectionData->getHost())
            || (isset($config[self::KEY_CONNECTION][$connection]['dbname'])
                && $config[self::KEY_CONNECTION][$connection]['dbname'] !== $connectionData->getDbName())
        ) {
            return false;
        }

        return true;
    }

    /**
     * Returns db configuration from env.php.
     */
    private function getDbConfigFromEnvFile(): array
    {
        if (null === $this->dbConfigFromEnvFile) {
            $this->dbConfigFromEnvFile = $this->configReader->read()['db'] ?? [];
        }
        return $this->dbConfigFromEnvFile;
    }

    /**
     * Returns connection data from relationship array
     *
     * @param string $key
     * @return ConnectionInterface
     */
    private function getConnectionData(string $key): ConnectionInterface
    {
        if (!isset($this->connectionData[$key]) || !($this->connectionData[$key] instanceof ConnectionInterface)) {
            $this->connectionData[$key] = $this->connectionDataFactory->create($key);
        }

        return $this->connectionData[$key];
    }

    /**
     * Returns configuration for connection
     *
     * @param ConnectionInterface $connectionData
     * @param bool $isSlave
     * @return array
     */
    public function getConnectionConfig(ConnectionInterface $connectionData, $isSlave = false): array
    {
        $host = $connectionData->getHost();

        if (!$host) {
            return [];
        }

        $port = $connectionData->getPort();

        $config = [
            'host' => empty($port) || $port == '3306' ? $host : $host . ':' . $port,
            'username' => $connectionData->getUser(),
            'dbname' => $connectionData->getDbName(),
            'password' => $connectionData->getPassword(),
        ];
        if ($isSlave) {
            $config['model'] = 'mysql4';
            $config['engine'] = 'innodb';
            $config['initStatements'] = 'SET NAMES utf8;';
            $config['active'] = '1';
        }
        return $config;
    }
}
