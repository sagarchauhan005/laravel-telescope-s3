<?php

namespace Laravel\Telescope\Storage;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Carbon\Carbon;

class S3EntriesRepository implements Contract, ClearableRepository, PrunableRepository, TerminableRepository
{
    protected $disk;
    protected $directory;
    protected $monitoredTags;

    public function __construct(string $disk, string $directory)
    {
        $this->disk = $disk;
        $this->directory = trim($directory, '/');
    }

    protected function entryPath($type, $batchId, $uuid)
    {
        return "{$this->directory}/{$type}/{$batchId}/{$uuid}.json";
    }

    public function find($id): EntryResult
    {
        // Scan all types and batches for the given uuid
        $files = Storage::disk($this->disk)->allFiles($this->directory);
        foreach ($files as $file) {
            if (str_ends_with($file, "/{$id}.json")) {
                $data = json_decode(Storage::disk($this->disk)->get($file), true);
                return $this->toEntryResult($data, $file);
            }
        }
        abort(404, 'Entry not found');
    }

    public function get($type, EntryQueryOptions $options)
    {
        $path = $type ? "{$this->directory}/{$type}" : $this->directory;
        $files = Storage::disk($this->disk)->allFiles($path);
        $results = collect();
        foreach ($files as $file) {
            if (!str_ends_with($file, '.json')) continue;
            $data = json_decode(Storage::disk($this->disk)->get($file), true);
            if ($this->matchesOptions($data, $options)) {
                $results->push($this->toEntryResult($data, $file));
            }
        }
        // Sort by sequence if present, otherwise by created_at
        return $results->sortByDesc(function($entry) {
            return $entry->sequence ?? ($entry->createdAt ? $entry->createdAt->timestamp : 0);
        })->take($options->limit)->values();
    }

    public function store(Collection $entries)
    {
        foreach ($entries as $entry) {
            $filePath = $this->entryPath($entry->type, $entry->batchId, $entry->uuid);
            Storage::disk($this->disk)->put($filePath, json_encode($entry->toArray()));
        }
    }

    public function update(Collection $updates)
    {
        // Not implemented for S3 (optional, depending on use-case)
        return null;
    }

    public function loadMonitoredTags()
    {
        // Optional: implement if you want to persist monitored tags in S3
        $this->monitoredTags = [];
    }

    public function isMonitoring(array $tags)
    {
        return !empty(array_intersect($tags, $this->monitoredTags ?? []));
    }

    public function monitoring()
    {
        return $this->monitoredTags ?? [];
    }

    public function monitor(array $tags)
    {
        $this->monitoredTags = array_unique(array_merge($this->monitoredTags ?? [], $tags));
    }

    public function stopMonitoring(array $tags)
    {
        $this->monitoredTags = array_diff($this->monitoredTags ?? [], $tags);
    }

    public function prune(DateTimeInterface $before, $keepExceptions)
    {
        $files = Storage::disk($this->disk)->allFiles($this->directory);
        $deleted = 0;
        foreach ($files as $file) {
            if (!str_ends_with($file, '.json')) continue;
            $data = json_decode(Storage::disk($this->disk)->get($file), true);
            $createdAt = Carbon::parse($data['created_at'] ?? null);
            if ($createdAt->lt($before)) {
                if ($keepExceptions && ($data['type'] ?? null) === 'exception') continue;
                Storage::disk($this->disk)->delete($file);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function clear()
    {
        Storage::disk($this->disk)->deleteDirectory($this->directory);
    }

    public function terminate()
    {
        $this->monitoredTags = null;
    }

    protected function matchesOptions($data, EntryQueryOptions $options)
    {
        if ($options->batchId && ($data['batch_id'] ?? null) !== $options->batchId) return false;
        if ($options->tag && (!isset($data['tags']) || !in_array($options->tag, $data['tags']))) return false;
        if ($options->familyHash && ($data['family_hash'] ?? null) !== $options->familyHash) return false;
        if ($options->beforeSequence && ($data['sequence'] ?? null) >= $options->beforeSequence) return false;
        if ($options->uuids && !in_array($data['uuid'] ?? null, $options->uuids)) return false;
        return true;
    }

    protected function toEntryResult($data, $file)
    {
        return new EntryResult(
            $data['uuid'] ?? null,
            $data['sequence'] ?? null,
            $data['batch_id'] ?? null,
            $data['type'] ?? null,
            $data['family_hash'] ?? null,
            $data['content'] ?? [],
            Carbon::parse($data['created_at'] ?? now()),
            $data['tags'] ?? []
        );
    }
} 