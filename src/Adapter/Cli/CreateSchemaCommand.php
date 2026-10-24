<?php
declare(strict_types=1);

namespace App\Adapter\Cli;

use App\Core\Service\SchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schema:create', description: 'Create a dynamic schema from a JSON file.')]
final class CreateSchemaCommand extends Command
{
    public function __construct(private readonly SchemaManager $schemas)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to schema metadata JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $input->getArgument('file');
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException('Schema file is not readable.');
            }
            $json = file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException('Cannot read schema file.');
            }
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || array_is_list($metadata)) {
                throw new \InvalidArgumentException('Metadata must be a JSON object.');
            }
            $output->writeln(json_encode($this->schemas->create($metadata)->toArray(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (\DomainException | \InvalidArgumentException | \JsonException $error) {
            $output->writeln($error->getMessage(), OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }
    }
}
