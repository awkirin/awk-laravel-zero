<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command as CommandAlias;

class YtDlpCommand extends Command
{
    protected $signature = 'yt-dlp {args* : Arguments to pass to yt-dlp}';
    protected $description = 'Downloads videos from YouTube using yt-dlp with default parameters';

    public function handle(): int
    {
        $defaultArgs = [
            '--retries', '20',
            '--compat-options', 'no-certifi',
            '--proxy', 'socks5://127.0.0.1:9150',
            '--embed-metadata',
            '--embed-thumbnail',
            '-f', 'bestvideo[vcodec^=avc1]+bestaudio[acodec^=mp4a]/best[ext=mp4]',
            '--merge-output-format', 'mp4',
        ];

        $userArgs = $this->argument('args');
        $args = array_merge($defaultArgs, $userArgs);

        $process = new Process(array_merge(['yt-dlp'], $args));
        $process->setTimeout(null); // без лимита времени
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
