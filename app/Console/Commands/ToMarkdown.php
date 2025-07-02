<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'sg:md')]
class ToMarkdown extends Command
{
    protected $description = 'Concatenate all PHP files in the given Laravel project directory into a Markdown file with paths as headers.';

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Base directory of a Laravel/PHP project');
        $this->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output Markdown file path', 'php_files.md');

        // Individual include options
        $this->addOption('include-vendor', null, InputOption::VALUE_NONE, 'Include the top-level vendor directory');
        $this->addOption('include-config', null, InputOption::VALUE_NONE, 'Include the top-level config directory');
        $this->addOption('include-bootstrap', null, InputOption::VALUE_NONE, 'Include the top-level bootstrap directory');
        $this->addOption('include-routes', null, InputOption::VALUE_NONE, 'Include the top-level routes directory');

        // Include all option
        $this->addOption('include-all', null, InputOption::VALUE_NONE, 'Include all top-level vendor, config, bootstrap, and routes directories');
    }

    public function handle()
    {
        $basePath = realpath($this->argument('path'));
        $outputPath = $this->option('output');

        if (!$basePath || !is_dir($basePath)) {
            $this->error("Invalid path: {$this->argument('path')}");
            return Command::FAILURE;
        }

        $includeAll = $this->option('include-all');

        // Determine directories to exclude by default
        $excludedTopDirs = ['vendor', 'config', 'bootstrap', 'routes', 'public'];

        if ($includeAll) {
            // Include all - empty exclude list
            $excludedTopDirs = [];
        } else {
            // Remove included directories from exclusion list if specified
            if ($this->option('include-vendor')) {
                $excludedTopDirs = array_diff($excludedTopDirs, ['vendor']);
            }
            if ($this->option('include-config')) {
                $excludedTopDirs = array_diff($excludedTopDirs, ['config']);
            }
            if ($this->option('include-bootstrap')) {
                $excludedTopDirs = array_diff($excludedTopDirs, ['bootstrap']);
            }
            if ($this->option('include-routes')) {
                $excludedTopDirs = array_diff($excludedTopDirs, ['routes']);
            }
        }

        $markdown = '';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getRealPath());

            $topLevelDir = explode(DIRECTORY_SEPARATOR, $relativePath)[0] ?? '';
            if (in_array($topLevelDir, $excludedTopDirs)) {
                continue;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
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
