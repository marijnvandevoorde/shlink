<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Db;

use Doctrine\DBAL\Connection;
use Shlinkio\Shlink\CLI\Command\Util\LockedCommandConfig;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Factory as Locker;
use Symfony\Component\Process\PhpExecutableFinder;

use function Functional\contains;

class CreateDatabaseCommand extends AbstractDatabaseCommand
{
    public const NAME = 'db:create';
    public const DOCTRINE_HELPER_SCRIPT = 'vendor/doctrine/orm/bin/doctrine.php';
    public const DOCTRINE_HELPER_COMMAND = 'orm:schema-tool:create';

    /** @var Connection */
    private $regularConn;
    /** @var Connection */
    private $noDbNameConn;

    public function __construct(
        Locker $locker,
        ProcessHelper $processHelper,
        PhpExecutableFinder $phpFinder,
        Connection $conn,
        Connection $noDbNameConn
    ) {
        parent::__construct($locker, $processHelper, $phpFinder);
        $this->regularConn = $conn;
        $this->noDbNameConn = $noDbNameConn;
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription(
                'Creates the database needed for shlink to work. It will do nothing if the database already exists'
            );
    }

    protected function lockedExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->checkDbExists();

        if ($this->schemaExists()) {
            $io->success('Database already exists. Run "db:migrate" command to make sure it is up to date.');
            return ExitCodes::EXIT_SUCCESS;
        }

        // Create database
        $io->writeln('<fg=blue>Creating database tables...</>');
        $this->runPhpCommand($output, [self::DOCTRINE_HELPER_SCRIPT, self::DOCTRINE_HELPER_COMMAND]);
        $io->success('Database properly created!');

        return ExitCodes::EXIT_SUCCESS;
    }

    private function checkDbExists(): void
    {
        if ($this->regularConn->getDatabasePlatform()->getName() === 'sqlite') {
            return;
        }

        // In order to create the new database, we have to use a connection where the dbname was not set.
        // Otherwise, it will fail to connect and will not be able to create the new database
        $schemaManager = $this->noDbNameConn->getSchemaManager();
        $databases = $schemaManager->listDatabases();
        $shlinkDatabase = $this->regularConn->getDatabase();

        if (! contains($databases, $shlinkDatabase)) {
            $schemaManager->createDatabase($shlinkDatabase);
        }
    }

    private function schemaExists(): bool
    {
        // If at least one of the shlink tables exist, we will consider the database exists somehow.
        // Any inconsistency will be taken care by the migrations
        $schemaManager = $this->regularConn->getSchemaManager();
        return ! empty($schemaManager->listTableNames());
    }

    protected function getLockConfig(): LockedCommandConfig
    {
        return new LockedCommandConfig($this->getName(), true);
    }
}
