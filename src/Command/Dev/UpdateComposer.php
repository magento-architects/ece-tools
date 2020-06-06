<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\Command\Dev;

use Magento\MagentoCloud\Command\Dev\UpdateComposer\ClearModuleRequirements;
use Magento\MagentoCloud\Command\Dev\UpdateComposer\ComposerGenerator;
use Magento\MagentoCloud\Config\ConfigException;
use Magento\MagentoCloud\Config\GlobalSection;
use Magento\MagentoCloud\Filesystem\Driver\File;
use Magento\MagentoCloud\Filesystem\FileList;
use Magento\MagentoCloud\Filesystem\FileSystemException;
use Magento\MagentoCloud\Package\UndefinedPackageException;
use Magento\MagentoCloud\Shell\ShellInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update composer command for deployment from git.
 *
 * @api
 */
class UpdateComposer extends Command
{
    public const NAME = 'dev:git:update-composer';
    private const OPTION_NO_INSTALL = 'no-install';
    const OPTION_IGNORE_PLATFORM_REQS = 'ignore-platform-reqs';

    /**
     * @var ComposerGenerator
     */
    private $composerGenerator;

    /**
     * @var ShellInterface
     */
    private $shell;

    /**
     * @var GlobalSection
     */
    private $globalSection;

    /**
     * @var FileList
     */
    private $fileList;

    /**
     * @var File
     */
    private $file;

    /**
     * @var ClearModuleRequirements
     */
    private $clearModuleRequirements;

    /**
     * @param ComposerGenerator $composerGenerator
     * @param ClearModuleRequirements $clearModuleRequirements
     * @param ShellInterface $shell
     * @param GlobalSection $globalSection
     * @param FileList $fileList
     * @param File $file
     */
    public function __construct(
        ComposerGenerator $composerGenerator,
        ClearModuleRequirements $clearModuleRequirements,
        ShellInterface $shell,
        GlobalSection $globalSection,
        FileList $fileList,
        File $file
    ) {
        $this->composerGenerator = $composerGenerator;
        $this->clearModuleRequirements = $clearModuleRequirements;
        $this->shell = $shell;
        $this->globalSection = $globalSection;
        $this->fileList = $fileList;
        $this->file = $file;

        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(static::NAME)
            ->setDescription('Updates composer for deployment from git.');
        $this->addOption(self::OPTION_NO_INSTALL, null, InputOption::VALUE_NONE, 'Do not run composer install/update');
        $this->addOption(self::OPTION_IGNORE_PLATFORM_REQS, null, InputOption::VALUE_NONE, 'Run composer with --ignore-platform-reqs');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConfigException
     * @throws FileSystemException
     * @throws UndefinedPackageException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $gitOptions = $this->globalSection->get(GlobalSection::VAR_DEPLOY_FROM_GIT_OPTIONS);

        $scripts = $this->composerGenerator->getInstallFromGitScripts($gitOptions['repositories']);
        foreach (array_slice($scripts, 1) as $script) {
            $this->shell->execute($script);
        }

        $composer = $this->composerGenerator->generate($gitOptions['repositories']);

        if (!empty($gitOptions['clear_magento_module_requirements'])) {
            $clearRequirementsScript = $this->clearModuleRequirements->generate();
            $composer['scripts']['install-from-git'][] = 'php ' . $clearRequirementsScript;
        }

        $this->file->filePutContents(
            $this->fileList->getMagentoComposer(),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($input->getOption(self::OPTION_NO_INSTALL)) {
            $output->writeln('Skipping composer update: "no-install" options is specified.');
            $output->writeln('Please run "composer update" manually, then commit and push changed files.');
        } else {
            $output->writeln('Run composer update');
            $composerCommand = 'composer update --ansi --no-interaction';
            if ($input->getOption(self::OPTION_IGNORE_PLATFORM_REQS)) {
                $composerCommand .= ' --ignore-platform-reqs';
            }
            $this->shell->execute($composerCommand);
            $output->writeln('Composer update finished.');
            $output->writeln('Please commit and push changed files.');
        }
    }
}
