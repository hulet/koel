<?php

namespace App\Services\Scanners;

use App\Models\User;
use App\Repositories\SongRepository;
use App\Services\SongService;
use App\Values\Scanning\ScanConfiguration;
use App\Values\Scanning\ScanResult;
use Symfony\Component\Finder\Finder;
use Throwable;

abstract class Scanner
{
    public function __construct(
        protected SongService $songService,
        protected SongRepository $songRepository,
        protected FileScanner $fileScanner,
        protected Finder $finder
    ) {
    }

    protected function handleIndividualFile(string $path, ScanConfiguration $config): ScanResult
    {
        try {
            $info = $this->fileScanner->scan($path);

            // Update $config based on the path
            if (str_contains($path, '/music/steve/')) {
                $config->owner = User::find(1); // Assign User model instance
            } elseif (str_contains($path, '/music/katie/')) {
                $config->owner = User::find(2); // Assign User model instance
            } elseif (str_contains($path, '/music/kids/')) {
                $config->owner = User::find(3); // Assign User model instance
            }

            $song = $this->songService->createOrUpdateSongFromScan($info, $config);

            if ($song->wasRecentlyCreated) {
                return ScanResult::success($info->path);
            }

            return $song->mtime === $info->mTime && !$config->force
                ? ScanResult::skipped($info->path)
                : ScanResult::success($info->path);
        } catch (Throwable $e) {
            return ScanResult::error($path, $e->getMessage());
        }
    }

    /**
     * Gather all applicable files in a given directory.
     *
     * @param string $path The directory's full path
     */
    protected function gatherFiles(string $path): Finder
    {
        $nameRegex = '/\.(' . implode('|', collect_accepted_audio_extensions()) . ')$/i';

        return $this->finder::create()
            ->ignoreUnreadableDirs()
            ->ignoreDotFiles((bool) config('koel.ignore_dot_files')) // https://github.com/koel/koel/issues/450
            ->files()
            ->followLinks()
            ->name($nameRegex)
            ->in($path);
    }

    protected static function setSystemRequirements(): void
    {
        if (!app()->runningInConsole()) {
            set_time_limit(config('koel.scan.timeout'));
        }

        if (config('koel.scan.memory_limit')) {
            ini_set('memory_limit', config('koel.scan.memory_limit') . 'M');
        }
    }
}
