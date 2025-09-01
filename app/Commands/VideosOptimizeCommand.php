<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

class VideosOptimizeCommand extends Command
{
    protected $signature = 'videos:optimize
        {--i|input=./ : Путь к входной директории с видео}
        {--o|output=./output : Путь к выходной директории}
        {--crf=38 : CRF значение для оптимизации (меньше = лучше качество, 18-28 рекомендуется)}
        {--f|format=mp4 : Формат выходного файла}
        {--y|yes : Пропустить подтверждение}
        {--force : Перезаписать существующие файлы}';

    protected $description = 'Оптимизирует видео файлы с помощью FFmpeg';

    /**
     * @throws \Throwable
     */
    public function handle(): void
    {
        $inputRaw = $this->option('input') ?: getcwd();
        $outputRaw = $this->option('output');
        $crf = (int) $this->option('crf');
        $format = $this->option('format') ?: 'mp4';
        $yes = $this->option('yes') ?: false;
        $force = $this->option('force') ?: false;


        if (!is_dir($inputRaw)) {
            $this->fail("Input path is not a directory: $inputRaw");
        }

        if (!is_dir($outputRaw)) {
            if (!mkdir($outputRaw, 0755, true)) {
                $this->fail("Failed to create output directory: $outputRaw");
            }
            $this->info("Created output directory: $outputRaw");
        }


        $input = realpath($inputRaw);
        $output = realpath($outputRaw);


        $videoFiles = $this->getVideoFiles($input);

        print_r($videoFiles->count());

        if ($videoFiles->count() === 0) {
            $this->info('Видео файлы не найдены');
            return;
        }

        $files = [];
        foreach ($videoFiles->getIterator() as $videoFile) {
            $extension = $videoFile->getExtension();
            $filename = $videoFile->getBasename(".{$extension}");
            $files[] = [
                'inputPath' => "{$videoFile->getRealPath()}",
                'outputPath' => "{$this->getOutputFileDir($videoFile, $input, $output )}/{$filename}.{$format}",
            ];
        }

        $this->table([
            'inputPath', 'outputPath'
        ], $files);

        if (!$yes && !$this->confirm('Продолжить обработку видео?', false)) {
            $this->info('Отменено пользователем');
        }

        $this->processVideos($files, $crf, $force);

    }

    protected function processVideos(array $files, int $crf, bool $force): void
    {
        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        foreach ($files as $file) {
            $this->processVideo($file['inputPath'], $file['outputPath'], $crf, $force);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\nОбработка завершена!");
    }

    protected function processVideo(string $inputPath, string $outputPath, int $crf, bool $force = false): void
    {
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = [
            'ffmpeg',
            '-i', $inputPath,
            '-crf', $crf,
        ];
        if ($force) {
            $command[] = '-y';
        }
        $command[] = $outputPath;

        $process = new Process($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error("Ошибка обработки файла: {$inputPath}");
            $this->error($process->getErrorOutput());
        }
    }

    protected function getVideoFiles(string $directory)
    {
        $videoExtensions = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp'];
        $allExtensions = array_merge(
            $videoExtensions,
            array_map('strtoupper', $videoExtensions)
        );
        $patterns = array_map(fn($ext) => "*.{$ext}", $allExtensions);

        return Finder::create()->in($directory)->files()->name($patterns);
    }

    protected function getOutputFileDir(SplFileInfo $file, string $inputDir, string $outputDir): string
    {
        $relativePath = str_replace($inputDir, '', $file->getPath());
        $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

        return $outputDir.($relativePath ? DIRECTORY_SEPARATOR.$relativePath : '');
    }
}
