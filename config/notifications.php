<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The V1 event catalogue
    |--------------------------------------------------------------------------
    |
    | Code owns this list; notification_preferences is a projection of it. A
    | preference row for an event that no longer exists is inert, and an event
    | with no preference row falls back to the defaults below -- so a new event
    | reaches people without a migration to backfill preferences for every user
    | who has ever signed up.
    |
    | `audience` decides which guard is notified, and it is the important field:
    |   agency -> the team who made the post
    |   client -> the portal users assigned to that brand
    |
    | An event MUST NOT have both. Sending a client an "approval failed" message
    | meant for the agency is the kind of leak this column exists to prevent.
    |
    */

    'events' => [

        'post.client_review' => [
            'audience' => 'client',
            'label' => 'Content is ready for your review',
            'defaults' => ['mail' => true, 'database' => true],
        ],

        'post.client_approved' => [
            'audience' => 'agency',
            'label' => 'A client approved a post',
            'defaults' => ['mail' => true, 'database' => true],
        ],

        'post.client_rejected' => [
            'audience' => 'agency',
            'label' => 'A client rejected a post',
            'defaults' => ['mail' => true, 'database' => true],
        ],

        'post.changes_requested' => [
            'audience' => 'agency',
            'label' => 'A client asked for changes',
            'defaults' => ['mail' => true, 'database' => true],
        ],

        /*
         | Publishing failures default to mail ON and cannot sensibly be
         | silenced by accident: a post that did not go out is the one thing an
         | agency must hear about, because the customer will notice first
         | otherwise.
         */
        'post.publish_failed' => [
            'audience' => 'agency',
            'label' => 'A post failed to publish',
            'defaults' => ['mail' => true, 'database' => true],
        ],

        /*
         | Published defaults to database ONLY. An agency running fifty posts a
         | day does not want fifty emails, and the ones who do can turn it on.
         | Defaulting this to mail is how a product teaches people to filter its
         | mail to a folder they never read -- including the failure notices.
         */
        'post.published' => [
            'audience' => 'agency',
            'label' => 'A post was published',
            'defaults' => ['mail' => false, 'database' => true],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    */

    'channels' => ['mail', 'database'],

    /*
     | Queue used for notification delivery. Separate from publishing so a slow
     | mail server can never delay a scheduled post going out.
     */
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

];
