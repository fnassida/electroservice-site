<?php
// Copy this file to private/config.php on the server and fill it in.
// config.php is never committed to Git.
return [
    'db_dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=DATABASE_NAME;charset=utf8mb4',
    'db_user'     => 'DATABASE_USER',
    'db_pass'     => 'DATABASE_PASSWORD',

    // Long random string. Used once at /portal/setup.php to create the admin account.
    'setup_key'   => 'CHANGE_ME',

    'site_name'   => 'Electro Service',
    'site_url'    => 'https://electroservice.net',
    'mail_from'   => 'info@electroservice.net',

    // Work board rules
    'platform_fee_rate' => 0.10,   // added on top of the job price, paid by the provider
    'min_job_amount'    => 50.00,
];
