<?php

namespace Magento\MagentoCloud\Process\Deploy\InstallUpdate\ConfigUpdate\Storage;

use Magento\MagentoCloud\Config\Stage\DeployInterface;

class Config
{
    /**
     * @var DeployInterface
     */
    private $stageConfig;

    /**
     * Config constructor.
     */
    public function __construct(
        DeployInterface $stageConfig
    )
    {
        $this->stageConfig = $stageConfig;
    }

    public function get(): array
    {
        $envStorageConfiguration = (array)$this->stageConfig->get(DeployInterface::VAR_STORAGE_CONFIGURATION);
        return $envStorageConfiguration;
    }
}
