<?php
require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', '/fake/wp/' );
define( 'CU_SCANNER_DIR', dirname( __DIR__ ) . '/' );
define( 'CU_SCANNER_VERSION', '1.0.0' );

WP_Mock::bootstrap();
