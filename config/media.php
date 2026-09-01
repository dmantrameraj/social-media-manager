<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Media lives on a PRIVATE disk and is served through signed, expiring URLs
    | after a policy check -- never by direct public path.
    | See docs/10-SECURITY.md §6.
    |
    */

    'disk' => env('MEDIA_DISK', 'local'),

    'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Upload restrictions
    |--------------------------------------------------------------------------
    |
    | MIME type is verified by sniffing file contents, never by trusting the
    | client-sent Content-Type header.
    |
    */

    'allowed_mimes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/quicktime',
        'application/pdf',
    ],

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'pdf',
    ],

    /*
     | SVG is an XSS vector: it can carry <script> and be served same-origin.
     | Enabling it requires a sanitiser, so it is off by default.
     */
    'allow_svg' => env('MEDIA_ALLOW_SVG', false),

    'max_upload_bytes' => (int) env('MEDIA_MAX_UPLOAD_BYTES', 104_857_600), // 100 MiB

    /*
    |--------------------------------------------------------------------------
    | Image variants
    |--------------------------------------------------------------------------
    |
    | Generated with GD via Intervention, on the `media` queue. Re-encoding also
    | strips embedded payloads and EXIF, which is a security benefit as much as
    | a size one.
    |
    */

    'variants' => [
        'thumb' => ['width' => 320, 'height' => 320],
        'preview' => ['width' => 1080, 'height' => 1080],
    ],

    /*
    |--------------------------------------------------------------------------
    | System folders
    |--------------------------------------------------------------------------
    |
    | Seeded for every new brand. Referenced by system_key, so the policy
    | refuses to rename or delete them -- other features would break silently.
    |
    */

    'system_folders' => [
        'logos' => 'Logos',
        'products' => 'Products',
        'reels' => 'Reels',
        'testimonials' => 'Testimonials',
        'brand_assets' => 'Brand Assets',
        'campaign_assets' => 'Campaign Assets',
    ],

];
