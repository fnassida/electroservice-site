<?php
// Copy this file to private/config.php on the server and fill in the two values marked CHANGE.
// config.php is never committed to Git.
return [
    'db_dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=u595185455_workboard;charset=utf8mb4',
    'db_user'     => 'u595185455_workboard',
    'db_pass'     => 'CHANGE: the database password you set in hPanel',

    // Long random string. Used once at /portal/setup.php to create the admin account.
    'setup_key'   => 'CHANGE_ME',   // CHANGE: any long random phrase only you know

    'site_name'   => 'Electro Service',
    'site_url'    => 'https://electroservice.net',
    'mail_from'   => 'info@electroservice.net',

    // Work board rules
    'platform_fee_rate' => 0.10,   // added on top of the job price, paid by the provider
    'min_job_amount'    => 50.00,
];
