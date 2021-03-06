<?php

namespace PhpGitHooks\Command;

use PhpGitHooks\Application\CodeSniffer\CheckCodeStyleCodeSnifferPreCommitExecutor;
use PhpGitHooks\Application\Config\HookConfigInterface;
use PhpGitHooks\Application\Config\PreCommitConfig;
use PhpGitHooks\Application\Message\MessageConfigData;
use PhpGitHooks\Application\PhpCsFixer\FixCodeStyleCsFixerPreCommitExecutor;
use PhpGitHooks\Application\PhpMD\CheckPhpMessDetectionPreCommitExecutor;
use PhpGitHooks\Application\PhpUnit\UnitTestPreCommitExecutor;
use PhpGitHooks\Container;
use PhpGitHooks\Infrastructure\Git\ExtractCommitedFiles;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class QualityCodeTool.
 */
class QualityCodeTool extends Application
{
    /** @var  OutputInterface */
    private $output;
    /** @var  array */
    private $files;
    /** @var  Container */
    private $container;
    /** @var  OutputHandler */
    private $outputTitleHandler;
    /** @var HookConfigInterface */
    private $configData;

    const PHP_FILES = '/^(.*)(\.php)$/';
    const JSON_FILES = '/^(.*)(\.json)$/';
    const COMPOSER_FILES = '/^composer\.(json|lock)$/';

    public function __construct($hookName = 'pre-commit')
    {
        $this->container = new Container();
        $this->outputTitleHandler = new OutputHandler();
        $this->configData = $this->container->get($this->getHookConfig($hookName));

        parent::__construct('Code Quality Tool');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $this->output->writeln('<fg=white;options=bold;bg=red>Pre-commit tool</fg=white;options=bold;bg=red>');
        $this->extractCommitFiles();

        $this->execute();

        if (true === $this->existsFiles()) {
            $this->output->writeln(GoodJobLogo::paint($this->getRightMessage()));
        }
    }

    private function extractCommitFiles()
    {
        $this->outputTitleHandler->setTitle('Fetching files');
        $this->output->write($this->outputTitleHandler->getTitle());

        $commitFiles = new ExtractCommitedFiles();

        $this->files = $commitFiles->getFiles();

        $result = true === $this->existsFiles() ? '0k' : 'No files changed';
        $this->output->writeln($this->outputTitleHandler->getSuccessfulStepMessage($result));
    }

    /**
     * @return array
     */
    private function processingFiles()
    {
        $files = [
            'php' => false,
            'composer' => false,
            'json' => false,
        ];

        foreach ($this->files as $file) {
            if (true === (bool) preg_match(self::PHP_FILES, $file)) {
                $files['php'] = true;
            }

            if (true === (bool) preg_match(self::COMPOSER_FILES, $file)) {
                $files['composer'] = true;
            }

            if (true === (bool) preg_match(self::JSON_FILES, $file)) {
                $files['json'] = true;
            }
        }

        return $files;
    }

    private function execute()
    {
        if (true === $this->isProcessingAnyComposerFile()) {
            $this->container->get('check.composer.files.pre.commit.executor')
                ->run($this->output, $this->files);
        }

        if (true === $this->isProcessingAnyJsonFile()) {
            $this->container->get('check.json.syntax.pre.commit.executor')
                ->run($this->output, $this->files, self::JSON_FILES);
        }

        if (true === $this->isProcessingAnyPhpFile()) {
            $this->container->get('check.php.syntax.lint.pre.commit.executor')
                ->run($this->output, $this->files);

            /** @var FixCodeStyleCsFixerPreCommitExecutor $csFixer */
            $csFixer = $this->container->get('fix.code.style.cs.fixer.pre.commit.executor');
            $csFixer->run($this->output, $this->files, self::PHP_FILES);

            /** @var CheckCodeStyleCodeSnifferPreCommitExecutor $codeSniffer */
            $codeSniffer = $this->container->get('check.code.style.code.sniffer.pre.commit.executor');
            $codeSniffer->run($this->output, $this->files, self::PHP_FILES);

            /** @var CheckPhpMessDetectionPreCommitExecutor $messDetector */
            $messDetector = $this->container->get('check.php.mess.detection.pre.commit.executor');
            $messDetector->run($this->output, $this->files, self::PHP_FILES);

            /** @var UnitTestPreCommitExecutor $phpUnit */
            $phpUnit = $this->container->get('unit.test.pre.commit.executor');
            $phpUnit->run($this->output);
        }
    }

    /**
     * @return bool
     */
    private function isProcessingAnyComposerFile()
    {
        $files = $this->processingFiles();

        return $files['composer'];
    }

    /**
     * @return bool
     */
    private function isProcessingAnyPhpFile()
    {
        $files = $this->processingFiles();

        return $files['php'];
    }

    /**
     * @return bool
     */
    private function isProcessingAnyJsonFile()
    {
        $files = $this->processingFiles();

        return $files['json'];
    }

    /**
     * @return bool
     */
    private function existsFiles()
    {
        return count($this->files) > 1 ? true : false;
    }

    /**
     * @return string
     */
    private function getRightMessage()
    {
        $messages = $this->configData->getMessages();

        return $messages[MessageConfigData::KEY_RIGHT_MESSAGE];
    }

    /**
     * @param $hookName
     *
     * @return string
     */
    private function getHookConfig($hookName)
    {
        return PreCommitConfig::HOOK_NAME === $hookName ? 'pre.commit.config' : 'pre.push.config';
    }
}
