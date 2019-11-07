<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\DB;

use Magento\MagentoCloud\DB\Data\ConnectionFactory;

/**
 * Class Dump generate mysqldump command with read only connection
 */
class Dump implements DumpInterface
{
    const DATABASE_MAIN = 'main';
    const DATABASE_CHECKOUT = 'checkout';
    const DATABASE_SALES = 'sales';

    const DATABASE_MAP = [
        self::DATABASE_MAIN => ConnectionFactory::CONNECTION_SLAVE,
        self::DATABASE_CHECKOUT => ConnectionFactory::CONNECTION_QUOTE_SLAVE,
        self::DATABASE_SALES => ConnectionFactory::CONNECTION_SALES_SLAVE,
    ];

    /**
     * Factory for creation database data connection classes
     *
     * @var ConnectionFactory
     */
    private $connectionFactory;

    /**
     * @param ConnectionFactory $connectionFactory
     */
    public function __construct(
        ConnectionFactory $connectionFactory
    )
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * Returns mysqldump command for executing in shell.
     *
     * {@inheritdoc}
     */
    public function getCommand(string $database = self::DATABASE_MAIN): string
    {
        $connectionData = $this->connectionFactory->create(self::DATABASE_MAP[$database]);
        $command = 'mysqldump -h ' . escapeshellarg($connectionData->getHost())
            . ' -u ' . escapeshellarg($connectionData->getUser());

        $port = $connectionData->getPort();
        if (!empty($port)) {
            $command .= ' -P ' . escapeshellarg($port);
        }

        $password = $connectionData->getPassword();
        if ($password) {
            $command .= ' -p' . escapeshellarg($password);
        }
        $command .= ' ' . escapeshellarg($connectionData->getDbName())
            . ' --single-transaction --no-autocommit --quick';

        return $command;
    }
}
