<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| Global kill switches for modules that are incomplete or that we may need to
| stop quickly in production. Per-tenant and per-plan gating is separate and
| lives in feature_flags / plan_features -- this layer sits above both, so a
| module can be switched off platform-wide regardless of any tenant's setting.
|
| See docs/12-ROADMAP.md and docs/00-PROJECT-OVERVIEW.md section 3.
|
*/

return [

    /*
     | Autopilot is off by default. It generates content on a cadence, so a
     | misconfiguration is visible to clients rather than just to us.
     */
    'autopilot' => (bool) env('FEATURE_AUTOPILOT', false),

    'analytics' => (bool) env('FEATURE_ANALYTICS', false),
    'unified_inbox' => (bool) env('FEATURE_UNIFIED_INBOX', false),
    'crm' => (bool) env('FEATURE_CRM', false),
    'white_label' => (bool) env('FEATURE_WHITE_LABEL', false),
    'reseller' => (bool) env('FEATURE_RESELLER', false),
    'whatsapp' => (bool) env('FEATURE_WHATSAPP', false),

];
