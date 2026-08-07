<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Where a pilot's simulator photos live on a machine with no bucket.
         *
         * Deliberately not the `public` disk. A photo is private to the pilot
         * who took it, and `public` is symlinked into the document root — a
         * file there is readable by anyone who can guess its path, which
         * hands back exactly the unguarded access the signed URLs exist to
         * prevent. Served through Laravel instead, so the local disk issues
         * expiring links the same way the S3 driver does and a developer
         * exercises the production path rather than a laxer one.
         */
        'photos' => [
            'driver' => 'local',
            'root' => storage_path('app/photos'),
            // Served from its own prefix rather than `/photos`, which the
            // photo log's own routes already own. A served disk registers
            // `GET {prefix}/{path}`, so sharing the prefix would collide with
            // the first `GET /photos/{photo}` anyone adds.
            'url' => mb_rtrim((string) env('APP_URL', 'http://localhost'), '/').'/drone-photos',
            'visibility' => 'private',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Drone Photo Disk
    |--------------------------------------------------------------------------
    |
    | Which disk a pilot's simulator photos are written to, read back from and
    | deleted from. Named here rather than hard-coded at the three call sites
    | so that moving the photo log between disks stays one change: a host with
    | an ephemeral filesystem — which is every deploy target this application
    | has — loses the whole log on release unless this points at object
    | storage, and a developer with no bucket still gets a working log.
    |
    */

    'photo_disk' => env('PHOTO_DISK', 'photos'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
