<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'sg:to-md')]
class ToMarkdown extends Command
{
    protected $description = 'Concatenate all PHP files in the given Laravel project directory into a Markdown file with paths as headers.';
    protected bool $excludeVendor = true;

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Base directory of a Laravel/PHP project');
        $this->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output Markdown file path', 'php_files.md');
    }

    public function handle()
    {
        $basePath = realpath($this->argument('path'));
        $outputPath = $this->option('output');

        if (!$basePath || !is_dir($basePath)) {
            $this->error("Invalid path: {$this->argument('path')}");
            return Command::FAILURE;
        }

        $markdown = '';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($this->excludeVendor && $file->getFilename() == 'vendor'
                && ($file->isDir() || $file->isLink())) {

                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getRealPath());
                $content = file_get_contents($file->getRealPath());

                $markdown .= "### `{$relativePath}`\n\n";
                $markdown .= "```php\n{$content}\n```\n\n";
            }
        }

        file_put_contents($outputPath, $markdown);
        $this->info("PHP files exported to: {$outputPath}");

        return Command::SUCCESS;
    }
}
