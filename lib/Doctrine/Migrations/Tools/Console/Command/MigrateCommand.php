<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tools\Console\Command;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Migration;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function count;
use function getcwd;
use function is_bool;
use function sprintf;
use function substr;

class MigrateCommand extends AbstractCommand
{
    protected function configure() : void
    {
        $this
            ->setName('migrations:migrate')
            ->setDescription(
                'Execute a migration to a specified version or the latest available version.'
            )
            ->addArgument(
                'version',
                InputArgument::OPTIONAL,
                'The version number (YYYYMMDDHHMMSS) or alias (first, prev, next, latest) to migrate to.',
                'latest'
            )
            ->addOption(
                'write-sql',
                null,
                InputOption::VALUE_OPTIONAL,
                'The path to output the migration SQL file instead of executing it. Default to current working directory.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Execute the migration as a dry run.'
            )
            ->addOption(
                'query-time',
                null,
                InputOption::VALUE_NONE,
                'Time all the queries individually.'
            )
            ->addOption(
                'allow-no-migration',
                null,
                InputOption::VALUE_NONE,
                'Don\'t throw an exception if no migration is available (CI).'
            )
            ->setHelp(<<<EOT
The <info>%command.name%</info> command executes a migration to a specified version or the latest available version:

    <info>%command.full_name%</info>

You can optionally manually specify the version you wish to migrate to:

    <info>%command.full_name% YYYYMMDDHHMMSS</info>

You can specify the version you wish to migrate to using an alias:

    <info>%command.full_name% prev</info>
    <info>These alias are defined : first, latest, prev, current and next</info>

You can specify the version you wish to migrate to using an number against the current version:

    <info>%command.full_name% current+3</info>

You can also execute the migration as a <comment>--dry-run</comment>:

    <info>%command.full_name% YYYYMMDDHHMMSS --dry-run</info>

You can output the would be executed SQL statements to a file with <comment>--write-sql</comment>:

    <info>%command.full_name% YYYYMMDDHHMMSS --write-sql</info>

Or you can also execute the migration without a warning message which you need to interact with:

    <info>%command.full_name% --no-interaction</info>

You can also time all the different queries if you wanna know which one is taking so long:

    <info>%command.full_name% --query-time</info>
EOT
        );

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $configuration = $this->getMigrationConfiguration($input, $output);
        $migration     = $this->createMigration($configuration);

        $this->outputHeader($configuration, $output);

        $timeAllqueries = $input->getOption('query-time');

        $dryRun = (bool) $input->getOption('dry-run');
        $configuration->setIsDryRun($dryRun);

        $executedMigrations  = $configuration->getMigratedVersions();
        $availableMigrations = $configuration->getAvailableVersions();

        $version = $this->getVersionNameFromAlias(
            $input->getArgument('version'),
            $output,
            $configuration
        );

        if ($version === '') {
            return 1;
        }

        $executedUnavailableMigrations = array_diff($executedMigrations, $availableMigrations);

        if (! empty($executedUnavailableMigrations)) {
            $output->writeln(sprintf(
                '<error>WARNING! You have %s previously executed migrations in the database that are not registered migrations.</error>',
                count($executedUnavailableMigrations)
            ));

            foreach ($executedUnavailableMigrations as $executedUnavailableMigration) {
                $output->writeln(sprintf(
                    '    <comment>>></comment> %s (<comment>%s</comment>)',
                    $configuration->getDateTime($executedUnavailableMigration),
                    $executedUnavailableMigration
                ));
            }

            $question = 'Are you sure you wish to continue? (y/n)';

            if (! $this->canExecute($question, $input, $output)) {
                $output->writeln('<error>Migration cancelled!</error>');

                return 1;
            }
        }

        $path = $input->getOption('write-sql');

        if ($path) {
            $path = is_bool($path) ? getcwd() : $path;
            $migration->writeSqlFile($path, $version);

            return 0;
        }

        $cancelled = false;

        $migration->setNoMigrationException($input->getOption('allow-no-migration'));

        $result = $migration->migrate(
            $version,
            $dryRun,
            $timeAllqueries,
            function () use ($input, $output, &$cancelled) {
                $question = 'WARNING! You are about to execute a database migration that could result in schema changes and data loss. Are you sure you wish to continue? (y/n)';

                $canContinue = $this->canExecute($question, $input, $output);
                $cancelled   = ! $canContinue;

                return $canContinue;
            }
        );

        if ($cancelled) {
            $output->writeln('<error>Migration cancelled!</error>');

            return 1;
        }

        return 0;
    }

    protected function createMigration(Configuration $configuration) : Migration
    {
        return new Migration($configuration);
    }

    private function canExecute(
        string $question,
        InputInterface $input,
        OutputInterface $output
    ) : bool {
        if ($input->isInteractive() && ! $this->askConfirmation($question, $input, $output)) {
            return false;
        }

        return true;
    }

    private function getVersionNameFromAlias(
        string $versionAlias,
        OutputInterface $output,
        Configuration $configuration
    ) : string {
        $version = $configuration->resolveVersionAlias($versionAlias);

        if ($version === null) {
            if ($versionAlias === 'prev') {
                $output->writeln('<error>Already at first version.</error>');

                return '';
            }

            if ($versionAlias === 'next') {
                $output->writeln('<error>Already at latest version.</error>');

                return '';
            }

            if (substr($versionAlias, 0, 7) === 'current') {
                $output->writeln('<error>The delta couldn\'t be reached.</error>');

                return '';
            }

            $output->writeln(sprintf(
                '<error>Unknown version: %s</error>',
                OutputFormatter::escape($versionAlias)
            ));

            return '';
        }

        return $version;
    }
}
