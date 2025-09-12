<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Process;

class YoutubeVideoDownloadCommand extends Command
{
    protected $signature = 'youtube:video:download {url : YouTube video URL}';
    protected $description = 'Скачивает видео с YouTube через yt-dlp с нужными параметрами';

    public function handle(): int
    {
        $url = $this->argument('url');

        $this->info("Скачивание видео: {$url}");

        $process = new Process([
            'yt-dlp',
            '--proxy', 'socks5://127.0.0.1:9150',
            '--merge-output-format', 'mp4',
            $url,
        ]);

        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->error($buffer);
            } else {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->error('Ошибка при загрузке видео');
            return CommandAlias::FAILURE;
        }

        $this->info('Видео успешно скачано!');
        return CommandAlias::SUCCESS;
    }

}
