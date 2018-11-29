<?php

namespace Conduit\Testing;

use Storage;

trait MockStorage
{
    /**
     * This should be called by your test's setUp() method
     */
    public function setupMockStorage()
    {
        $disks = config('filesystems.disks', []);
        foreach ($disks as $name => $config) {
            #if ($config['driver'] != 'local') {
                $path = storage_path("test/$name");
                config(["filesystems.disks.$name" => [
                    'driver' => 'local',
                    'root' => $path,
                ]]);
                $client = Storage::createLocalDriver(['root' => $path]);
                Storage::set($name, $client);
            #}
        }
    }
}
