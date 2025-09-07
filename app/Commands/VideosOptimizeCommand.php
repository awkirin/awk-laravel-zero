<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class VideosOptimizeCommand extends Command
{
    protected string $statusFile = '';
    protected $signature = 'videos:optimize
        {--i|input=./ : Путь к входной директории с видео}
        {--o|output=./output : Путь к выходной директории}
        {--crf=38 : CRF значение для оптимизации (меньше = лучше качество, 18-28 рекомендуется)}
        {--f|format=mp4 : Формат выходного файла}
        {--y|yes : Пропустить подтверждение}
        {--force : Перезаписать существующие файлы}';

    protected $description = 'Оптимизирует видео файлы с помощью FFmpeg';

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        if (!$this->isFfmpegAvailable()) {
            $this->fail('ffmpeg не найден');
        }


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

        $this->statusFile = "{$output}/_status.csv";


        $videoFiles = $this->getVideoFiles($input);

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
            $this->fail('Отменено пользователем');
        }


        $this->createStatusFile();


        $this->processVideos($files, $crf, $force);

    }

    protected function createStatusFile(): void
    {
        $header = "Имя файла;Размер до;Размер после;Сжатие;Статус\n";
        file_put_contents($this->statusFile, $header);
        $this->info("Файл статуса создан: {$this->statusFile}");
    }

    protected function addStatusEntry(
        string $filePath,
        string $filename,
        string $sizeBefore,
        string $sizeAfter,
        string $compressing,
        string $status
    ): void {
        $entry = "{$filename};{$sizeBefore};{$sizeAfter};{$compressing};{$status}\n";
        file_put_contents($filePath, $entry, FILE_APPEND | LOCK_EX);
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $size = round($bytes / pow(1024, $i), 2);

        return $size.' '.$units[$i];
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
        $this->info("Статус обработки сохранен в: $this->statusFile");
    }

    protected function processVideo(string $inputPath, string $outputPath, int $crf, bool $force = false): void
    {
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }


        // Получаем размер исходного файла
        $originalSize = filesize($inputPath);
        $originalSizeFormatted = $this->formatFileSize($originalSize);


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

//        if (!$process->isSuccessful()) {
//            $this->error("Ошибка обработки файла: {$inputPath}");
//            $this->error($process->getErrorOutput());
//        }
        if (!$process->isSuccessful()) {
            $this->error("Ошибка обработки файла: {$inputPath}");
            $this->error($process->getErrorOutput());

            // Записываем ошибку в статус
            if ($this->statusFile) {
                $this->addStatusEntry(
                    $this->statusFile,
                    basename($inputPath),
                    $originalSizeFormatted,
                    '',
                    '',
                    'ERROR'
                );
            }
        } else {
            // Получаем размер обработанного файла
            $processedSize = file_exists($outputPath) ? filesize($outputPath) : 0;
            $processedSizeFormatted = $this->formatFileSize($processedSize);

            // Рассчитываем процент сжатия
            $compressionRatio = $originalSize > 0 ? round((1 - $processedSize / $originalSize) * 100, 2) : 0;

            // Записываем успешный статус
            if ($this->statusFile) {
                $this->addStatusEntry(
                    $this->statusFile,
                    basename($inputPath),
                    $originalSizeFormatted,
                    $processedSizeFormatted,
                    $compressionRatio.'%',
                    'OK'
                );
            }

            $this->info("\nОбработано: ".basename($inputPath).
                " | Было: {$originalSizeFormatted} | Стало: {$processedSizeFormatted} | Сжатие: {$compressionRatio}%");
        }
    }

    protected function getVideoFiles(string $directory)
    {
        $videoExtensions = [ 'mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp' ];
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

    protected function isFfmpegAvailable(): bool
    {
        try {
            $process = new Process(['ffmpeg', '-version']);
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
