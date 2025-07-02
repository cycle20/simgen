<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'sg:x')]
class ExtractSource extends Command
{
    protected $description = 'Extract PHP files from a Markdown file with headers as filenames.';

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Base directory to extract PHP files into', '.');
        $this->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Input Markdown file path', 'php_files.md');
        $this->addOption('exclude', 'x', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Files or directories to exclude', []);
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Suppress Git unstaged changes confirmation');
    }

    public function handle()
    {
        $basePath = realpath($this->argument('path'));
        $inputPath = $this->option('input');
        $excludes = $this->option('exclude');
        $force = $this->option('force');

        if (!$basePath || !is_dir($basePath)) {
            $this->error("Invalid path: {$this->argument('path')}");
            return Command::FAILURE;
        }

        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            $this->error("Cannot read input Markdown file: {$inputPath}");
            return Command::FAILURE;
        }

        // Check if inside a git repository and its status
        if (!$force && $this->isGitRepository($basePath)
            && $this->hasUnstagedChanges($basePath)
            && !$this->confirm('You have unstaged changes in your git repository. Continue anyway?')) {

            $this->info('Aborted by user.');
            return Command::FAILURE;
        }

        $content = file_get_contents($inputPath);
        if ($content === false) {
            $this->error("Failed to read Markdown file: {$inputPath}");
            return Command::FAILURE;
        }

        // Parse markdown for code blocks preceded by ### `filename`
        $pattern = '/^###\s+`(.+?)`\s*$(?:\r?\n)+```php\s*(.*?)```/ms';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            $this->warn("No PHP code blocks found in Markdown file.");
            return Command::SUCCESS;
        }

        $writtenFiles = 0;

        foreach ($matches as $match) {
            $relativeFilePath = $match[1];
            $phpCode = $match[2];
            $phpCode = substr($phpCode, -1) == "\n"
                ? substr_replace($phpCode, '', -1)
                : $match[2];

            // Normalize file path and check excludes
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeFilePath);
            $skip = false;
            foreach ($excludes as $exclude) {
                // Normalize exclude path and check if $normalizedPath starts with exclude path
                $normalizedExclude = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $exclude), DIRECTORY_SEPARATOR);
                if (
                    $normalizedPath === $normalizedExclude ||
                    str_starts_with($normalizedPath, $normalizedExclude . DIRECTORY_SEPARATOR)
                ) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                $this->info("Skipped excluded file: {$relativeFilePath}");
                continue;
            }

            // Compose full output path
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $normalizedPath;

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                $this->error("Failed to create directory: {$dir}");
                continue;
            }

            // Write PHP code to file
            if (file_put_contents($fullPath, $phpCode) === false) {
                $this->error("Failed to write file: {$fullPath}");
                continue;
            }

            $this->info("Extracted: {$relativeFilePath}");
            $writtenFiles++;
        }

        $this->info("Extraction complete. {$writtenFiles} file(s) written.");

        return Command::SUCCESS;
    }

    protected function isGitRepository(string $path): bool
    {
        // Check if .git directory exists in this or parent dirs
        $current = $path;
        while ($current !== DIRECTORY_SEPARATOR && $current !== '') {
            if (is_dir($current . DIRECTORY_SEPARATOR . '.git')) {
                return true;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        return false;
    }

    protected function hasUnstagedChanges(string $path): bool
    {
        $output = [];
        $status = 0;
        $escapedPath = escapeshellarg($path);
        $gitCommand = "git -C $escapedPath status --porcelain";
        exec("$gitCommand", $output, $status);

        if ($status !== 0) {
            // git command failed - assume no unstaged changes
            return false;
        }

        // git status --porcelain output lines format:
        // XY <path>
        // X = staged status, Y = unstaged status
        // We consider unstaged changes if Y is not space

        foreach ($output as $line) {
            // Each line is at least 3 chars: XY + space
            // Defensive check:
            if (strlen($line) < 3) {
                continue;
            }
            $unstagedStatus = $line[1]; // second char is unstaged status
            if ($unstagedStatus !== ' ') {
                // print status output
                $this->warn('UNSTAGED CHANGE(S):');
                $this->warn('===================');
                $this->warn(implode("\n", $output));
                // unstaged change detected
                return true;
            }
        }

        // No unstaged changes found
        return false;
    }
}
