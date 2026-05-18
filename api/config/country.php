<?php

declare(strict_types=1);

/*
 * Country runtime profile — Uganda POE Sentinel deployment.
 *
 * Single source of truth for the country identity. `legacy_code` matches
 * the canonical form stored in ref_countries.country_code so
 * `config('country.code')` and `config('country.legacy_code')` can replace
 * hardcoded scope defaults across controllers.
 */

return [
    'code'           => 'UG',
    'iso2'           => 'UG',
    'iso3'           => 'UGA',
    'legacy_code'    => 'Uganda',
    'name'           => 'Uganda',
    'display_name'   => 'ECSA Uganda POE',
    'currency'       => 'UGX',
    'timezone'       => 'Africa/Kampala',
    'locale'         => 'en',
    'dialing_code'   => '+256',
    'flag_emoji'     => '🇺🇬',
    'admin_email'    => 'admin@ug-poe.ecsahc.com',

    // Primary TO address for every transactional dispatch (alerts, digests,
    // bundles, briefs). Cached at config:cache time so it survives Octane
    // restarts. Override via env(MAIL_PRIMARY_TO_ADDRESS) for staging.
    'primary_to_address' => env('MAIL_PRIMARY_TO_ADDRESS', 'mosesebong@gmail.com'),
];
