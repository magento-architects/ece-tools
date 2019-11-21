<?php

namespace Magento\MagentoCloud\Process\Deploy\InstallUpdate\ConfigUpdate;

use Magento\MagentoCloud\Config\Deploy\Reader as ConfigReader;
use Magento\MagentoCloud\Config\Deploy\Writer as ConfigWriter;
use Magento\MagentoCloud\Process\Deploy\InstallUpdate\ConfigUpdate\Storage\Config;
use Magento\MagentoCloud\Process\ProcessInterface;

class Storage implements ProcessInterface
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
     * @var Config
     */
    private $storageConfig;

    /**
     * Storage constructor.
     */
    public function __construct(
        ConfigReader $configReader,
        ConfigWriter $configWriter,
        LoggerInterface $logger,
        Config $storageConfig
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
     */
    public function execute()
    {
        $config = $this->configReader->read();
        $this->logger->info('Updating storage configuration.');

        $config['storage'] = $this->storageConfig->get();

        $this->configWriter->create($config);
    }
}
