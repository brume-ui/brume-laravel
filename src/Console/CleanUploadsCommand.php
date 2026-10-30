<?php

namespace Brume\Console;

use Brume\Uploads\TempUpload;
use Brume\Uploads\TempUploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanUploadsCommand extends Command
{
    protected $signature = 'brume:uploads:clean';

    protected $description = 'Delete expired temporary uploads and old orphaned temporary files';

    public function handle(): int
    {
        $connections = TempUploads::connections();
        $disks = [config('brume.uploads.temp_disk', 'local')];
        foreach ($connections as $connection) {
            $disks = array_merge($disks, TempUpload::on($connection)->distinct()->pluck('disk')->all());
            TempUpload::on($connection)->where('expires_at', '<=', now())->pluck('id')->each(function ($id) use ($connection) {
                DB::connection($connection)->transaction(function () use ($connection, $id) {
                    $row = TempUpload::on($connection)->whereKey($id)->where('expires_at', '<=', now())->lockForUpdate()->first();
                    if ($row === null) {
                        return;
                    }
                    if (preg_match('~^brume-tmp/[A-Za-z0-9]{40}\.[a-zA-Z0-9]+$~D', $row->path)) {
                        Storage::disk($row->disk)->delete($row->path);
                    }
                    $row->delete();
                });
            });
        }
        foreach (array_unique($disks) as $disk) {
            foreach (Storage::disk($disk)->allFiles('brume-tmp') as $path) {
                if (Storage::disk($disk)->lastModified($path) >= now()->subMinutes(config('brume.uploads.temp_ttl', 1440))->timestamp) {
                    continue;
                }
                $tracked = false;
                foreach ($connections as $connection) {
                    if (TempUpload::on($connection)->where('disk', $disk)->where('path', $path)->exists()) {
                        $tracked = true;
                        break;
                    }
                }
                if (! $tracked) {
                    Storage::disk($disk)->delete($path);
                }
            }
        }

        return self::SUCCESS;
    }
}
