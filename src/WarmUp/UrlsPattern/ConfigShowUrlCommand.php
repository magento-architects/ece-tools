<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\WarmUp\UrlsPattern;

use Magento\MagentoCloud\Shell\MagentoShell;
use Magento\MagentoCloud\Shell\ShellException;
use Magento\MagentoCloud\Shell\ShellFactory;

/**
 * Executes config:show:urls command and decodes result.
 */
class ConfigShowUrlCommand
{
    private static $commandName = 'config:show:urls';

    /**
     * @var MagentoShell
     */
    private $magentoShell;

    /**
     * @param ShellFactory $shellFactory
     */
    public function __construct(ShellFactory $shellFactory)
    {
        $this->magentoShell = $shellFactory->createMagento();
    }

    /**
     * Executes config:show:urls command and decodes result.
     *
     * @param array $arguments
     * @return mixed
     * @throws ShellException If command was executed with error
     * @throws ParseResultException If command result was parsed with error
     */
    public function execute(array $arguments)
    {
        $process = $this->magentoShell->execute(
            self::$commandName,
            $arguments
        );

        $urls = json_decode($process->getOutput(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ParseResultException(sprintf(
                'Can\'t parse result from command %s: %s',
                self::$commandName,
                json_last_error_msg()
            ));
        }

        return $urls;
    }
}
