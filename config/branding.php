<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform branding defaults
|--------------------------------------------------------------------------
|
| Every Blade template reads branding through BrandingResolver, never from a
| hardcoded string. White labelling (Phase 8) then becomes a matter of the
| resolver returning tenant values instead of these -- with no template
| changes at all.
|
| See docs/01-ARCHITECTURE.md section 6.
|
*/

return [

    'app_name' => env('BRANDING_APP_NAME', 'Social Media Manager'),
    'support_email' => env('BRANDING_SUPPORT_EMAIL', 'support@example.com'),

    'colors' => [
        'primary' => env('BRANDING_PRIMARY_COLOR', '#4f46e5'),
        'secondary' => env('BRANDING_SECONDARY_COLOR', '#0f172a'),
    ],

    'logo_path' => env('BRANDING_LOGO_PATH'),
    'favicon_path' => env('BRANDING_FAVICON_PATH'),

];
