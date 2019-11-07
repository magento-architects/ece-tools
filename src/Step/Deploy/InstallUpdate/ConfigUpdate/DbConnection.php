<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\Step\Deploy\InstallUpdate\ConfigUpdate;

use Magento\MagentoCloud\Config\ConfigMerger;
use Magento\MagentoCloud\Config\Database\MergedConfig;
use Magento\MagentoCloud\Config\Deploy\Reader as ConfigReader;
use Magento\MagentoCloud\Config\Deploy\Writer as ConfigWriter;
use Magento\MagentoCloud\Config\Stage\DeployInterface;
use Magento\MagentoCloud\DB\Data\RelationshipConnectionFactory;
use Magento\MagentoCloud\Step\StepInterface;
use Psr\Log\LoggerInterface;

/**
 * Updates DB connection configuration.
 */
class DbConnection implements StepInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConfigWriter
     */
    private $configWriter;

    /**
     * @var ConfigReader
     */
    private $configReader;

    /**
     * @var MergedConfig
     */
    private $mergedConfig;

    /**
     * @var DeployInterface
     */
    private $stageConfig;

    /**
     * @var ConfigMerger
     */
    private $configMerger;

    /**
     * @var RelationshipConnectionFactory
     */
    private $connectionFactory;

    /**
     * @param DeployInterface $stageConfig
     * @param MergedConfig $mergedConfig
     * @param ConfigWriter $configWriter
     * @param ConfigReader $configReader
     * @param ConfigMerger $configMerger
     * @param RelationshipConnectionFactory $connectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        DeployInterface $stageConfig,
        MergedConfig $mergedConfig,
        ConfigWriter $configWriter,
        ConfigReader $configReader,
        ConfigMerger $configMerger,
        RelationshipConnectionFactory $connectionFactory,
        LoggerInterface $logger
    ) {
        $this->stageConfig = $stageConfig;
        $this->mergedConfig = $mergedConfig;
        $this->configWriter = $configWriter;
        $this->configReader = $configReader;
        $this->configMerger = $configMerger;
        $this->logger = $logger;
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Magento\MagentoCloud\Filesystem\FileSystemException
     */
    public function execute()
    {
        $config = $this->configReader->read();

        $this->logger->info('Updating env.php DB connection configuration.');
        $dbConfig = $this->mergedConfig->get();
        $config[MergedConfig::KEY_DB] = $dbConfig[MergedConfig::KEY_DB];
        $config[MergedConfig::KEY_RESOURCE] = $dbConfig[MergedConfig::KEY_RESOURCE];

        $this->addLoggingAboutSlaveConnection($config[MergedConfig::KEY_DB]);
        $this->configWriter->create($config);
    }

    /**
     * Adds logging about slave connection.
     *
     * @param array $config
     */
    private function addLoggingAboutSlaveConnection(array $config)
    {
        $envDbConfig = $this->stageConfig->get(DeployInterface::VAR_DATABASE_CONFIGURATION);
        $useSlave = $this->stageConfig->get(DeployInterface::VAR_MYSQL_USE_SLAVE_CONNECTION);
        $isMergeRequired = !$this->configMerger->isEmpty($envDbConfig)
            && !$this->configMerger->isMergeRequired($envDbConfig);

        $connections = array_keys($config[MergedConfig::KEY_CONNECTION]);
        foreach ($connections as $connection) {
            $connectionType = MergedConfig::CONNECTION_MAP[$connection][MergedConfig::KEY_CONNECTION];
            $connectionData = $this->connectionFactory->create($connectionType);
            if (!$connectionData->getHost() || !$useSlave || $isMergeRequired) {
                continue;
            } elseif (!$this->mergedConfig->isDbConfigCompatibleWithSlaveConnection($connection)) {
                $this->logger->warning(
                    'You have changed db configuration that not compatible with default slave connection.'
                );
            } elseif (!empty($config[MergedConfig::KEY_SLAVE_CONNECTION][$connection])) {
                $this->logger->info('Set DB slave connection for `' . $connection . '` connection');
            } else {
                $this->logger->info(
                    'Enabling of the variable MYSQL_USE_SLAVE_CONNECTION had no effect ' .
                    'because slave connection is not configured on your environment.'
                );
            }
        }
    }
}
