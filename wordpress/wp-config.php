<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'wordpress');

/** MySQL database username */
define('DB_USER', 'wordpressuser');

/** MySQL database password */
define('DB_PASSWORD', 'x');

/** MySQL hostname */
define('DB_HOST', 'localhost');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');



/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         '</bK&,<,xM*brbPL;b+1lj+I/-|M$r:d>`l0uY=0p-P2r.NK4[1P`TerK@hNW{|I');
define('SECURE_AUTH_KEY',  ']QK}d,z_WA,{lIxWW3+1Y7Wr~}y)daM1l6H$~E#cHVJB]qc5_etw%EtsIZ,,DhGC');
define('LOGGED_IN_KEY',    'VH01;ow6^aj(N`$zFtkpr;n$pz$)3*Kxa&t@PL^7n#u:/!_+~}$l`kGn>f|=I.W-');
define('NONCE_KEY',        'rf8X-xrFpS*jnJ t*Zh.yvirTq7cO|BnE$#@bJ)/9]NC]m<8@mLRn_[h0WNcm1m>');
define('AUTH_SALT',        ' X^=)E9&],#z/^bIju{gi7T&>POpeL^{F0;(&uZ8G>y}}/#9SG1lVSSk7Tqz:wir');
define('SECURE_AUTH_SALT', '-#SK;%i3::l4haz~&mp]YL,f;noR0Nq,XMF>L+!.g9](!K12<^:e;cHzgyD,v9i1');
define('LOGGED_IN_SALT',   'y@l&xWPV?~E;##u4/5P& 82CpT+J6;29#;|o9s&H%ZY<=.%B;IWdyC>b]TC?r6L&');
define('NONCE_SALT',       'AoN2Fp(4Eh!n2vC!EO2lX7D} ,.um1orN*-=M1FnWKbySBfZ suq35L wtA1y%JY');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */ 
define('WP_DEBUG', false);

/* Multisite */
define( 'WP_ALLOW_MULTISITE', true );

define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', true);
define('DOMAIN_CURRENT_SITE', 'milan.hu');
define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);
define( 'SUNRISE', 'on' );
/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
