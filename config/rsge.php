<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        'basic' => [
            'username' => env('RSGE_USERNAME', ''),
            'password' => env('RSGE_PASSWORD', ''),
        ],
        // The waybill service signs in with a service user (su / sp), made on the rs.ge portal.
        'waybill' => [
            'su' => env('RSGE_WAYBILL_SU', ''),
            'sp' => env('RSGE_WAYBILL_SP', ''),
        ],
        'oauth' => [
            'client_id' => env('RSGE_OAUTH_CLIENT_ID', ''),
            'client_secret' => env('RSGE_OAUTH_CLIENT_SECRET', ''),
            'token_url' => env('RSGE_OAUTH_TOKEN_URL', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Endpoints
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'cash_register' => [
            'wsdl' => env('RSGE_CASH_REGISTER_WSDL', 'https://services.rs.ge/taxservice/taxpayerservice.asmx?WSDL'),
            'soap_options' => [
                'trace' => false,
                'exceptions' => true,
                'connection_timeout' => 30,
                'cache_wsdl' => WSDL_CACHE_DISK,
                'keep_alive' => true,
                'user_agent' => 'laravel-rsge',
            ],
        ],
        'waybill' => [
            'wsdl' => env('RSGE_WAYBILL_WSDL', 'https://services.rs.ge/WayBillService/WayBillService.asmx?WSDL'),
            'soap_options' => [
                'trace' => false,
                'exceptions' => true,
                'connection_timeout' => 30,
                'cache_wsdl' => WSDL_CACHE_DISK,
                'keep_alive' => true,
                'user_agent' => 'laravel-rsge',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Transport
    |--------------------------------------------------------------------------
    */

    'default_transport' => env('RSGE_TRANSPORT', 'soap'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => env('RSGE_LOGGING_ENABLED', false),
        'channel' => env('RSGE_LOG_CHANNEL', null),
    ],

];
