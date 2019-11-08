<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\DB;

use Magento\MagentoCloud\Filesystem\DirectoryList;
use Magento\MagentoCloud\Shell\ShellInterface;
use Psr\Log\LoggerInterface;
use Magento\MagentoCloud\DB\Data\ConnectionFactory;
use Magento\MagentoCloud\DB\Data\ConnectionInterface;
use Magento\MagentoCloud\Config\Deploy\Reader as ConfigReader;

/**
 * Creates database dump and archives it
 */
class DumpCreator
{
    const DATABASE_MAIN = 'main';
    const DATABASE_CHECKOUT = 'checkout';
    const DATABASE_SALES = 'sales';

    const CONNECTION_DEFAULT = 'default';
    const CONNECTION_CHECKOUT = 'checkout';
    const CONNECTION_SALES = 'sales';

    const DATABASE_MAP = [
        self::DATABASE_MAIN => ConnectionFactory::CONNECTION_SLAVE,
        self::DATABASE_CHECKOUT => ConnectionFactory::CONNECTION_QUOTE_SLAVE,
        self::DATABASE_SALES => ConnectionFactory::CONNECTION_SALES_SLAVE,
    ];

    const CONNECTION_MAP = [
        self::CONNECTION_DEFAULT => ConnectionFactory::CONNECTION_SLAVE,
        self::CONNECTION_CHECKOUT => ConnectionFactory::CONNECTION_QUOTE_SLAVE,
        self::CONNECTION_SALES => ConnectionFactory::CONNECTION_SALES_SLAVE,
    ];

    /**
     * Factory for creation database data connection classes
     *
     * @var ConnectionFactory
     */
    private $connectionFactory;

    /**
     * Template for dump file name where %s should be changed to timestamp for uniqueness
     */
    const DUMP_FILE_NAME_TEMPLATE = 'dump-%s-%s.sql.gz';

    /**
     * Lock file name.
     * During the dumping this file is locked to prevent running dump by others.
     */
    const LOCK_FILE_NAME = 'dbdump.lock';

    /**
     * Timeout for mysqldump command in seconds
     */
    const DUMP_TIMEOUT = 3600;

    /**
     * Used for execution shell operations
     *
     * @var ShellInterface
     */
    private $shell;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var DumpInterface
     */
    private $dump;

    /**
     * @var ConfigReader
     */
    private $configReader;

    /**
     * @param DumpInterface $dump
     * @param LoggerInterface $logger
     * @param ShellInterface $shell
     * @param DirectoryList $directoryList
     * @param ConnectionFactory $connectionFactory
     * @param ConfigReader $configReader
     */
    public function __construct(
        DumpInterface $dump,
        LoggerInterface $logger,
        ShellInterface $shell,
        DirectoryList $directoryList,
        ConnectionFactory $connectionFactory,
        ConfigReader $configReader
    ) {
        $this->dump = $dump;
        $this->logger = $logger;
        $this->shell = $shell;
        $this->directoryList = $directoryList;
        $this->connectionFactory = $connectionFactory;
        $this->configReader = $configReader;
    }

    /**
     * The process to create dumps of databases
     *
     * @param array $databases
     * @param bool $removeDefiners
     * @throws \Magento\MagentoCloud\Package\UndefinedPackageException
     */
    public function process(array $databases, bool $removeDefiners)
    {
        if (empty($databases)) {
            $connections = array_intersect_key(
                self::CONNECTION_MAP,
                $this->configReader->read()['db']['connection'] ?? []
            );
            foreach ($connections as $connection) {
                $database = array_flip(self::DATABASE_MAP)[$connection];
                $connectionData = $this->connectionFactory->create($connection);
                $this->create($database, $connectionData, $removeDefiners);
            }
        }
    }

    /**
     * Creates database dump and archives it.
     *
     * Lock file is created at the beginning of dumping.
     * This file has dual purpose, it creates a lock, so another DB backup process cannot be executed,
     * as well as serves a log with the name of created dump file.
     * If any error happened during dumping, dump file is removed.
     *
     * @param string $database
     * @param ConnectionInterface $connectionData
     * @param bool $removeDefiners
     * @return void
     * @throws \Magento\MagentoCloud\Package\UndefinedPackageException
     */
    private function create(string $database, ConnectionInterface $connectionData, bool $removeDefiners)
    {

        $dumpFileName = sprintf(self::DUMP_FILE_NAME_TEMPLATE, $database, time());

        $temporaryDirectory = sys_get_temp_dir();

        $dumpFile = $temporaryDirectory . '/' . $dumpFileName;
        $lockFile = $this->directoryList->getVar() . '/' . self::LOCK_FILE_NAME;

        // Lock file has dual purpose, it creates a lock, so another DB backup process cannot be executed,
        // as well as serves as a log with the name of created dump file.
        $lockFileHandle = fopen($lockFile, "w+");

        // Lock the sql dump so staging sync doesn't start using it until we're done.
        $this->logger->info('Waiting for lock on db dump.');

        if ($lockFileHandle === false) {
            $this->logger->error('Could not get the lock file!');
            return;
        }

        try {
            if (flock($lockFileHandle, LOCK_EX)) {
                $this->logger->info("Start creation DB dump for the $database database ...");

                $command = 'timeout ' . self::DUMP_TIMEOUT . ' ' . $this->dump->getCommand($connectionData);
                if ($removeDefiners) {
                    $command .= ' | sed -e \'s/DEFINER[ ]*=[ ]*[^*]*\*/\*/\'';
                }
                $command .= ' | gzip > ' . $dumpFile;

                $process = $this->shell->execute('bash -c "set -o pipefail; ' . $command . '"');

                if ($process->getExitCode() !== ShellInterface::CODE_SUCCESS) {
                    $this->logger->error('Error has occurred during mysqldump');
                    $this->shell->execute('rm ' . $dumpFile);
                } else {
                    $this->logger->info("Finished DB dump for $database database, it can be found here: " . $dumpFile);
                    fwrite(
                        $lockFileHandle,
                        sprintf('[%s] Dump was written in %s', date("Y-m-d H:i:s"), $dumpFile) . PHP_EOL
                    );
                    fflush($lockFileHandle);
                }
                flock($lockFileHandle, LOCK_UN);
            } else {
                $this->logger->info('Dump process is locked!');
            }
            fclose($lockFileHandle);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            fclose($lockFileHandle);
        }
    }
}
