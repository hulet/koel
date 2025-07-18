<?php

namespace App\Repositories;

use App\Builders\SongBuilder;
use App\Enums\PlayableType;
use App\Facades\License;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Folder;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Podcast;
use App\Models\Song;
use App\Models\User;
use App\Repositories\Contracts\ScoutableRepository;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder as ScoutBuilder;

/**
 * @extends Repository<Song>
 * @implements ScoutableRepository<Song>
 */
class SongRepository extends Repository implements ScoutableRepository
{
    private const DEFAULT_QUEUE_LIMIT = 500;

    public function __construct(private readonly FolderRepository $folderRepository)
    {
        parent::__construct();
    }

    public function findOneByPath(string $path): ?Song
    {
        return Song::query()->where('path', $path)->first();
    }

    /** @return Collection|array<array-key, Song> */
    public function getAllStoredOnCloud(): Collection
    {
        return Song::query()->storedOnCloud()->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getRecentlyAdded(int $count = 10, ?User $scopedUser = null): Collection
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->latest()
            ->limit($count)
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getMostPlayed(int $count = 7, ?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->where('interactions.play_count', '>', 0)
            ->orderByDesc('interactions.play_count')
            ->limit($count)
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getRecentlyPlayed(int $count = 7, ?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->where('interactions.play_count', '>', 0)
            ->addSelect('interactions.last_played_at')
            ->orderByDesc('interactions.last_played_at')
            ->limit($count)
            ->get();
    }

    public function getForListing(
        array $sortColumns,
        string $sortDirection,
        bool $ownSongsOnly = false,
        ?User $scopedUser = null,
        int $perPage = 50
    ): Paginator {
        $scopedUser ??= $this->auth->user();

        return Song::query(type: PlayableType::SONG, user: $scopedUser)
            ->accessible()
            ->withMetaData()
            ->when(
                $ownSongsOnly,
                static fn (SongBuilder $query) => $query->where('songs.owner_id', $scopedUser->id)
            )
            ->sort($sortColumns, $sortDirection)
            ->simplePaginate($perPage);
    }

    public function getByGenre(
        Genre $genre,
        array $sortColumns,
        string $sortDirection,
        ?User $scopedUser = null,
        int $perPage = 50
    ): Paginator {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->whereRelation('genres', 'genres.id', $genre->id)
            ->sort($sortColumns, $sortDirection)
            ->simplePaginate($perPage);
    }

    public function getWithNoGenre(
        array $sortColumns,
        string $sortDirection,
        ?User $scopedUser = null,
        int $perPage = 50
    ): Paginator {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->whereDoesntHave('genres')
            ->sort($sortColumns, $sortDirection)
            ->simplePaginate($perPage);
    }

    /** @return Collection|array<array-key, Song> */
    public function getForQueue(
        array $sortColumns,
        string $sortDirection,
        int $limit = self::DEFAULT_QUEUE_LIMIT,
        ?User $scopedUser = null,
    ): Collection {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->sort($sortColumns, $sortDirection)
            ->limit($limit)
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getFavorites(?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->where('interactions.liked', true)
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getByAlbum(Album $album, ?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->whereBelongsTo($album)
            ->orderBy('songs.disc')
            ->orderBy('songs.track')
            ->orderBy('songs.title')
            ->get();
    }

    public function paginateInFolder(?Folder $folder = null, ?User $scopedUser = null): Paginator
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->storedLocally()
            ->withMetaData()
            ->when($folder, static fn (SongBuilder $query) => $query->where('folder_id', $folder->id)) // @phpstan-ignore-line
            ->when(!$folder, static fn (SongBuilder $query) => $query->whereNull('folder_id'))
            ->orderBy('songs.path')
            ->simplePaginate(50);
    }

    /** @return Collection|array<array-key, Song> */
    public function getByArtist(Artist $artist, ?User $scopedUser = null): Collection
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->where(static function (SongBuilder $query) use ($artist): void {
                $query->whereBelongsTo($artist)
                    ->orWhereHas('album', static function (Builder $albumQuery) use ($artist): void {
                        $albumQuery->whereBelongsTo($artist);
                    });
            })
            ->orderBy('songs.album_name')
            ->orderBy('songs.disc')
            ->orderBy('songs.track')
            ->orderBy('songs.title')
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getByStandardPlaylist(Playlist $playlist, ?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->leftJoin('playlist_song', 'songs.id', '=', 'playlist_song.song_id')
            ->leftJoin('playlists', 'playlists.id', '=', 'playlist_song.playlist_id')
            ->when(License::isPlus(), static function (SongBuilder $query): SongBuilder {
                return
                    $query->join('users as collaborators', 'playlist_song.user_id', '=', 'collaborators.id')
                        ->addSelect(
                            'collaborators.public_id as collaborator_public_id',
                            'collaborators.name as collaborator_name',
                            'collaborators.email as collaborator_email',
                            'collaborators.avatar as collaborator_avatar',
                            'playlist_song.created_at as added_at'
                        );
            })
            ->where('playlists.id', $playlist->id)
            ->orderBy('playlist_song.position')
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getRandom(int $limit, ?User $scopedUser = null): Collection
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /** @return Collection|array<array-key, Song> */
    public function getMany(array $ids, bool $preserveOrder = false, ?User $scopedUser = null): Collection
    {
        $songs = Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->whereIn('songs.id', $ids)
            ->get();

        return $preserveOrder ? $songs->orderByArray($ids) : $songs; // @phpstan-ignore-line
    }

    /** @param array<string> $ids */
    public function countAccessibleByIds(array $ids, ?User $scopedUser = null): int
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->whereIn('songs.id', $ids)
            ->count();
    }

    /**
     * Gets several songs, but also includes collaborative information.
     *
     * @return Collection|array<array-key, Song>
     */
    public function getManyInCollaborativeContext(array $ids, ?User $scopedUser = null): Collection
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->when(License::isPlus(), static function (SongBuilder $query): SongBuilder {
                return
                    $query->leftJoin('playlist_song', 'songs.id', '=', 'playlist_song.song_id')
                        ->leftJoin('playlists', 'playlists.id', '=', 'playlist_song.playlist_id')
                        ->join('users as collaborators', 'playlist_song.user_id', '=', 'collaborators.id')
                        ->addSelect(
                            'collaborators.public_id as collaborator_public_id',
                            'collaborators.name as collaborator_name',
                            'collaborators.email as collaborator_email',
                            'playlist_song.created_at as added_at'
                        );
            })
            ->whereIn('songs.id', $ids)
            ->get();
    }

    /** @param string $id */
    public function getOne($id, ?User $scopedUser = null): Song
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->findOrFail($id);
    }

    /** @param string $id */
    public function findOne($id, ?User $scopedUser = null): ?Song
    {
        return Song::query(user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->find($id);
    }

    public function countSongs(?User $scopedUser = null): int
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->count();
    }

    public function getTotalSongLength(?User $scopedUser = null): float
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->sum('length');
    }

    /** @return Collection|array<array-key, Song> */
    public function getRandomByGenre(Genre $genre, int $limit, ?User $scopedUser = null): Collection
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->whereRelation('genres', 'genres.id', $genre->id)
            ->limit($limit)
            ->inRandomOrder()
            ->get();
    }

    /** @return array<string> */
    public function getEpisodeGuidsByPodcast(Podcast $podcast): array
    {
        return $podcast->episodes()->pluck('episode_guid')->toArray();
    }

    /** @return Collection<Song> */
    public function getEpisodesByPodcast(Podcast $podcast): Collection
    {
        return $podcast->episodes;
    }

    /** @return Collection<Song>|array<array-key, Song> */
    public function getUnderPaths(
        array $paths,
        int $limit = 500,
        bool $random = false,
        ?User $scopedUser = null
    ): Collection {
        $paths = array_map(static fn (?string $path) => $path ? trim($path, DIRECTORY_SEPARATOR) : '', $paths);

        if (!$paths) {
            return Collection::empty();
        }

        $hasRootPath = in_array('', $paths, true);
        $scopedUser ??= $this->auth->user();

        return Song::query(type: PlayableType::SONG, user: $scopedUser)
            ->accessible()
            ->withMetaData()
            // if the root path is included, we don't need to filter by folder
            ->when(!$hasRootPath, function (SongBuilder $query) use ($paths, $scopedUser): void {
                $folders = $this->folderRepository->getByPaths($paths, $scopedUser);
                $query->whereIn('songs.folder_id', $folders->pluck('id'));
            })
            ->when(!$random, static fn (SongBuilder $query): SongBuilder => $query->orderBy('songs.path'))
            ->when($random, static fn (SongBuilder $query): SongBuilder => $query->inRandomOrder())
            ->limit($limit)
            ->get();
    }

    /**
     * Fetch songs **directly** in a specific folder (or the media root if null).
     * This does not include songs in subfolders.
     *
     * @return Collection<Song>|array<array-key, Song>
     */
    public function getInFolder(?Folder $folder = null, int $limit = 500, ?User $scopedUser = null): Collection
    {
        return Song::query(type: PlayableType::SONG, user: $scopedUser ?? $this->auth->user())
            ->accessible()
            ->withMetaData()
            ->limit($limit)
            ->when($folder, static fn (SongBuilder $query) => $query->where('songs.folder_id', $folder->id)) // @phpstan-ignore-line
            ->when(!$folder, static fn (SongBuilder $query) => $query->whereNull('songs.folder_id'))
            ->orderBy('songs.path')
            ->get();
    }

    /** @return Collection<Song>|array<array-key, Song> */
    public function search(string $keywords, int $limit, ?User $scopedUser = null): Collection
    {
        $isPlus = once(static fn () => License::isPlus());
        $scopedUser ??= $this->auth->user();

        return $this->getMany(
            ids: Song::search($keywords)
                ->when($isPlus, static fn (ScoutBuilder $query) => $query->where('owner_id', $scopedUser?->id))
                ->get()
                ->take($limit)
                ->modelKeys(),
            preserveOrder: true,
            scopedUser: $scopedUser,
        );
    }
}
