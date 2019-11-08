<?php
/**
 * Copyright © Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\Command;

use Magento\MagentoCloud\DB\DumpCreator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class DbDump for safely creating backup of database
 */
class DbDump extends Command
{
    const NAME = 'db-dump';

    const ARGUMENT_DATABASES = 'databases';

    const OPTION_REMOVE_DEFINERS = 'remove-definers';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DumpCreator
     */
    private $dumpCreator;

    /**
     * @param DumpCreator $dumpCreator
     * @param LoggerInterface $logger
     */
    public function __construct(DumpCreator $dumpCreator, LoggerInterface $logger)
    {
        $this->dumpCreator = $dumpCreator;
        $this->logger = $logger;

        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName(self::NAME)
            ->setDescription('Creates backup of database')
            ->addArgument(
                self::ARGUMENT_DATABASES,
                InputArgument::IS_ARRAY,
                sprintf(
                    'Databases to backup. Available values: %s or empty. By default will backup the databases'
                    . ' based on the databases configuration in the file <magento_root>/app/etc/env.php ',
                    implode(',', array_keys(DumpCreator::DATABASE_MAP))
                ),
                []
            )
            ->addOption(
                self::OPTION_REMOVE_DEFINERS,
                'd',
                InputOption::VALUE_NONE,
                'Remove definers from the database dump'
            );

        parent::configure();
    }

    /**
     * Creates DB dump.
     * Command requires confirmation before execution.
     *
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'We suggest to enable maintenance mode before running this command. Do you want to continue [y/N]?',
            false
        );

        if (!$helper->ask($input, $output, $question) && $input->isInteractive()) {
            return null;
        }

        $databases = $input->getArgument(self::ARGUMENT_DATABASES);
        $breakExecution = false;
        foreach ($databases as $database) {
            if (!isset(DumpCreator::DATABASE_MAP[$database])) {
                $this->logger->error(sprintf(
                    'Incorrect the argument value: %s. Available values: %s or empty',
                    $database,
                    implode(',', array_keys(DumpCreator::DATABASE_MAP))
                ));
                $breakExecution = true;
            }
        }

        if ($breakExecution) {
            return null;
        }

        try {
            $this->logger->info('Starting backup.');
            $this->dumpCreator->process(
                $databases,
                (bool)$input->getOption(self::OPTION_REMOVE_DEFINERS)
            );
            $this->logger->info('Backup completed.');
            return null;
        } catch (\Exception $exception) {
            $this->logger->critical($exception->getMessage());
            throw $exception;
        }
    }
}
