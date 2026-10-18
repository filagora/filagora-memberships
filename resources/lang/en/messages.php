<?php

declare(strict_types=1);

return [
    'navigation_groups' => [
        'memberships' => 'Memberships',
    ],

    'plans' => [
        'navigation_label' => 'Membership plans',
        'model_label' => 'membership plan',
        'plural_model_label' => 'membership plans',
        'sync' => 'Sync :provider plans',
        'sync_failed' => ':provider sync failed',
        'sync_nothing' => 'Nothing to sync',
        'sync_nothing_body' => 'Every currency on this plan already exists at the provider.',
        'sync_done' => ':provider plans created',
        'sync_done_body' => 'Created billing plans for: :currencies',
        'locked_price' => 'Locked: this currency is already synced to a billing provider. To change the price, delete this row, save, then add a new row for this currency and sync again.',
        'auto_id' => 'Leave blank and use the sync button to create this automatically.',
        'manual_id' => 'The provider\'s own identifier for this price.',
    ],

    'billing_events' => [
        'navigation_label' => 'Billing events',
        'model_label' => 'billing event',
        'plural_model_label' => 'billing events',
        'tab_failed' => 'Failed',
        'tab_all' => 'All events',
        'badge_tooltip' => '{1} :count billing event failed|[2,*] :count billing events failed',
    ],

    'memberships' => [
        'description' => 'Inspect membership records for this person and apply safe operational actions without editing provider-managed state directly.',
        'activate' => 'Activate local membership',
        'activated' => 'Local membership activated',
        'link' => 'Link subscription',
        'link_description' => 'For a subscription that predates this site. Enter the provider\'s subscription ID: its current status is pulled from the provider now, and its webhooks keep it in sync from then on.',
        'linked' => 'Subscription linked',
        'link_failed' => 'Could not link the subscription',
        'mark_canceling' => 'Mark canceling',
        'marked_canceling' => 'Membership marked canceling',
        'mark_canceled' => 'Mark canceled',
        'marked_canceled' => 'Membership marked canceled',
        'end_now' => 'End now',
        'ended' => 'Membership ended immediately',
        'billing_events' => 'Billing events',
    ],
];
