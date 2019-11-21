<?php

namespace Magento\MagentoCloud\Step\Deploy\InstallUpdate\ConfigUpdate;

use Magento\MagentoCloud\Config\Deploy\Reader as ConfigReader;
use Magento\MagentoCloud\Config\Deploy\Writer as ConfigWriter;
use Magento\MagentoCloud\Step\Deploy\InstallUpdate\ConfigUpdate\Storage\Config as StorageConfig;
use Magento\MagentoCloud\Step\StepException;
use Magento\MagentoCloud\Step\StepInterface;

class Storage implements StepInterface
{
    /**
     * @var ConfigReader
     */
    private $configReader;
    /**
     * @var ConfigWriter
     */
    private $configWriter;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var StorageConfig
     */
    private $storageConfig;

    /**
     * Storage constructor.
     */
    public function __construct(
        ConfigReader $configReader,
        ConfigWriter $configWriter,
        LoggerInterface $logger,
        StorageConfig $storageConfig
    )
    {
        $this->configReader = $configReader;
        $this->configWriter = $configWriter;
        $this->logger = $logger;
        $this->storageConfig = $storageConfig;
    }


    /**
     * Executes the step.
     *
     * @return void
     * @throws StepException
     */
    public function execute()
    {
        $config = $this->configReader->read();
        $this->logger->info('Updating storage configuration.');

        $config['storage'] = $this->storageConfig->get();

        $this->configWriter->create($config);
    }
}
