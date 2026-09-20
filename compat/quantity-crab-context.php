<?php
/** Apply only to a staged/local plugin copy; checks the exact expected source. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { fwrite( STDERR, "Usage: php compat/quantity-crab-context.php /staged/quantity-crab [--check]\n" ); exit( 1 ); }
$path = rtrim( $argv[1], '/' ) . '/includes/class-quantity-crab-discounts.php';
$source = file_get_contents( $path );
if ( false === $source ) { exit( 1 ); }
$patched = $source;
$replacements = array(
	"final class Quantity_Crab_Discounts {" => "final class Quantity_Crab_Discounts {\n\tconst CUSTOMER_CONTEXT_VERSION = 1;",
	"\$is_guest        = ! is_user_logged_in();" => "\$is_guest        = (bool) apply_filters( 'quantity_crab_customer_is_guest', ! is_user_logged_in(), \$cart );",
);
if ( false === strpos( $source, 'const CUSTOMER_CONTEXT_VERSION = 1;' ) ) {
	foreach ( $replacements as $from => $to ) {
		if ( 1 !== substr_count( $patched, $from ) ) { fwrite( STDERR, "Unsupported Quantity Crab baseline; no files changed.\n" ); exit( 2 ); }
		$patched = str_replace( $from, $to, $patched );
	}
}
if ( false === strpos( $patched, "apply_filters( 'quantity_crab_customer_is_guest'," ) ) { fwrite( STDERR, "Context marker exists without required filter.\n" ); exit( 2 ); }
if ( ! in_array( '--check', $argv, true ) && $source !== $patched ) {
	$backup = $path . '.cart-cat-original-' . substr( hash( 'sha256', $source ), 0, 12 );
	if ( ! is_file( $backup ) && false === file_put_contents( $backup, $source ) ) { exit( 3 ); }
	$temp = $path . '.cart-cat-new';
	if ( false === file_put_contents( $temp, $patched ) ) { exit( 3 ); }
	chmod( $temp, fileperms( $path ) & 0777 );
	if ( ! rename( $temp, $path ) ) { exit( 3 ); }
}
echo "Quantity Crab customer-context compatibility validated" . ( in_array( '--check', $argv, true ) ? " (check only).\n" : ".\n" );
