<?php
/** Isolated UPS address contract: synthetic provider responses, no WordPress or network. */
namespace {
	if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { throw new \RuntimeException( 'Run this fixture directly with PHP CLI.' ); }
	define( 'ABSPATH', __DIR__ );
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	}
	class Fixture_OAuth {
		public function get_access_token() { $GLOBALS['ups_auth_calls']++; return $GLOBALS['ups_token']; }
	}
	class WC_Shipping_UPS {
		public $id = 'ups'; public $enabled = 'yes'; public $api = 'rest'; public $validation = true; public $supported = true;
		public function get_api_type() { return $this->api; }
		public function is_destination_address_validation_enabled() { return $this->validation; }
		public function country_supports_address_validation( $country ) { return $this->supported && in_array( $country, array( 'US', 'PR' ), true ); }
		public function get_ups_oauth() { return new Fixture_OAuth(); }
	}
	class WC_Shipping_Zones {
		public static function get_zone_matching_package( $package ) { $GLOBALS['ups_package'] = $package; return new Fixture_Zone(); }
	}
	class Fixture_Zone {
		public function get_shipping_methods( $enabled_only ) { $GLOBALS['ups_enabled_only'] = $enabled_only; return $GLOBALS['ups_methods']; }
	}
}
namespace WooCommerce\UPS\API\REST {
	class Address_Validator {
		private $address;
		public function __construct( $address, $token ) { $this->address = $address; if ( '' !== $token ) { $GLOBALS['ups_validator_address'] = $address; } }
		public function is_po_box() { return (bool) preg_match( '/^(P\.?[-\s]*O\.?[-\s]*B(?:ox)?\.?|Post\s+Office\s+Box)\s*\d+/i', $this->address['address_1'] ) || (bool) preg_match( '/^(P\.?[-\s]*O\.?[-\s]*B(?:ox)?\.?|Post\s+Office\s+Box)\s*\d+/i', $this->address['address_2'] ); }
		public function validate() { $GLOBALS['ups_validation_calls']++; if ( $GLOBALS['ups_throw'] ) { throw new \RuntimeException( 'secret raw UPS provider error' ); } }
		public function get_response() { return $GLOBALS['ups_response']; }
	}
}
namespace {
	require __DIR__ . '/../cart-cat/includes/class-address-validation.php';
	$count = 0;
	$assert = static function ( $condition, $message ) use ( &$count ) { $count++; if ( ! $condition ) { throw new \RuntimeException( $message ); } };
	$base = array( 'address_1' => '19925 Hogback Mountain Rd', 'address_2' => '', 'city' => 'Leesburg', 'state' => 'VA', 'postcode' => '20175', 'country' => 'US' );
	$raw = static function ( $address, $indicator = 'ValidAddressIndicator' ) {
		$lines = array( $address['address_1'] ); if ( '' !== $address['address_2'] ) { $lines[] = $address['address_2']; }
		return array( 'XAVResponse' => array( 'Response' => array( 'ResponseStatus' => array( 'Code' => '1' ) ), $indicator => '', 'Candidate' => array( array( 'AddressKeyFormat' => array( 'AddressLine' => $lines, 'PoliticalDivision2' => $address['city'], 'PoliticalDivision1' => $address['state'], 'PostcodePrimaryLow' => $address['postcode'], 'CountryCode' => $address['country'] ) ) ) ) );
	};
	$reset = static function () use ( $raw, $base ) {
		$GLOBALS['ups_methods'] = array( new WC_Shipping_UPS() ); $GLOBALS['ups_token'] = 'synthetic-token';
		$GLOBALS['ups_response'] = $raw( $base ); $GLOBALS['ups_throw'] = false; $GLOBALS['ups_auth_calls'] = 0; $GLOBALS['ups_validation_calls'] = 0;
		unset( $GLOBALS['ups_package'], $GLOBALS['ups_validator_address'] );
	};
	$validate = static function ( $address ) { return \Cart_Cat\Address_Validation::validate( $address ); };
	$reset();
	$result = $validate( array_merge( $base, array( 'email' => 'never-send@example.test', 'phone' => '2025550101', 'first_name' => 'NeverSend', 'company' => 'NeverSend', 'customer_reference' => 'NeverSend' ) ) );
	$assert( 'valid' === $result['status'] && array() === $result['candidates'], 'An exact valid UPS result is matched.' );
	$assert( true === $GLOBALS['ups_enabled_only'], 'Discovery asks Woo for enabled methods in the matching zone.' );
	$assert( array( 'destination' => $base ) === $GLOBALS['ups_package'], 'Matching zone uses only postal destination fields.' );
	$assert( $base === $GLOBALS['ups_validator_address'], 'Only six postal fields reach the provider validator; contacts and references are excluded.' );
	$assert( 1 === $GLOBALS['ups_auth_calls'] && 1 === $GLOBALS['ups_validation_calls'], 'Explicit validation uses existing authentication and one validation call.' );
	$assert( array( 'status', 'message', 'candidates' ) === array_keys( $result ) && ! str_contains( json_encode( $result ), 'synthetic-token' ), 'Public response contains only advisory result data.' );
	foreach ( array( null, 'address', array(), array_merge( $base, array( 'address_1' => array() ) ), array_merge( $base, array( 'city' => '' ) ), array_merge( $base, array( 'state' => '' ) ), array_merge( $base, array( 'postcode' => '2017' ) ), array_merge( $base, array( 'postcode' => '20175-123' ) ), array_merge( $base, array( 'country' => 'USA' ) ), array_merge( $base, array( 'address_1' => str_repeat( 'x', 201 ) ) ), array_merge( $base, array( 'city' => "Leesburg\nSecret" ) ) ) as $bad ) {
		$reset(); $result = $validate( $bad );
		$assert( is_wp_error( $result ) && 400 === $result->data['status'] && 0 === $GLOBALS['ups_auth_calls'] && ! isset( $GLOBALS['ups_package'] ), 'Incomplete or malformed user input fails before provider discovery.' );
	}
	$reset(); $result = $validate( array_merge( $base, array( 'country' => 'CA', 'state' => 'ON', 'postcode' => 'M5V 2T6' ) ) );
	$assert( 'unsupported' === $result['status'] && ! isset( $GLOBALS['ups_package'] ), 'Unsupported countries do not attempt UPS authentication or validation.' );
	$reset(); $result = $validate( array_merge( $base, array( 'country' => 'us', 'state' => 'va', 'city' => ' Leesburg ' ) ) );
	$assert( 'valid' === $result['status'], 'Case and surrounding whitespace do not require a correction.' );
	foreach ( array( 'enabled' => 'no', 'api' => 'xml', 'validation' => false, 'supported' => false, 'id' => 'other' ) as $field => $value ) {
		$reset(); $GLOBALS['ups_methods'][0]->$field = $value; $result = $validate( $base );
		$assert( 'unavailable' === $result['status'] && 0 === $GLOBALS['ups_auth_calls'], 'Disabled, incompatible or nonvalidating zone instances cannot authenticate.' );
	}
	$reset(); $GLOBALS['ups_methods'] = array( (object) array( 'id' => 'ups', 'enabled' => 'yes' ) );
	$assert( 'unavailable' === $validate( $base )['status'] && 0 === $GLOBALS['ups_auth_calls'], 'A similarly named shipping method cannot impersonate official UPS.' );
	$reset(); $GLOBALS['ups_methods'] = array();
	$assert( 'unavailable' === $validate( $base )['status'], 'Missing zone integration is unavailable.' );
	$reset(); $disabled = new WC_Shipping_UPS(); $disabled->enabled = 'no'; array_unshift( $GLOBALS['ups_methods'], $disabled );
	$assert( 'valid' === $validate( $base )['status'] && 1 === $GLOBALS['ups_auth_calls'], 'An enabled compatible instance is selected after an unsuitable one.' );
	foreach ( array( false, '', array( 'secret' ) ) as $token ) {
		$reset(); $GLOBALS['ups_token'] = $token; $result = $validate( $base );
		$assert( 'unavailable' === $result['status'] && 0 === $GLOBALS['ups_validation_calls'], 'Missing or malformed authentication never reports valid or calls validation.' );
	}
	foreach ( array( 'PO Box 123', 'P.O. Box 12', 'Post Office Box 19' ) as $po ) {
		$reset(); $result = $validate( array_merge( $base, array( 'address_1' => $po ) ) );
		$assert( 'unmatched' === $result['status'] && 0 === $GLOBALS['ups_auth_calls'], 'Native PO box rejection does not need provider authentication.' );
	}
	$reset(); $assert( 'unmatched' === $validate( array_merge( $base, array( 'address_2' => 'PO Box 123' ) ) )['status'], 'PO boxes on the second address line are rejected too.' );
	$reset(); $GLOBALS['ups_throw'] = true; $result = $validate( $base );
	$assert( 'unavailable' === $result['status'] && ! str_contains( json_encode( $result ), 'secret' ), 'Thrown provider errors are redacted and never pass validation.' );
	$malformed = array( null, false, array(), array( 'XAVResponse' => array() ), new WP_Error( 'secret', 'secret' ) );
	$response = $raw( $base ); unset( $response['XAVResponse']['Response'] ); $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['Response']['ResponseStatus']['Code'] = '0'; $malformed[] = $response;
	$response = $raw( $base ); unset( $response['XAVResponse']['ValidAddressIndicator'] ); $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['ValidAddressIndicator'] = null; $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['NoCandidatesIndicator'] = ''; $malformed[] = $response;
	$response = $raw( $base ); unset( $response['XAVResponse']['Candidate'] ); $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['Candidate'][0]['AddressKeyFormat']['PoliticalDivision2'] = array( 'secret' ); $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['Candidate'][0]['AddressKeyFormat']['AddressLine'] = array( array( 'bad' ) ); $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['Candidate'][0]['AddressKeyFormat']['CountryCode'] = 'CA'; $malformed[] = $response;
	$response = $raw( $base ); $response['XAVResponse']['Candidate'][0]['AddressKeyFormat']['PostcodeExtendedLow'] = '123'; $malformed[] = $response;
	foreach ( $malformed as $response ) {
		$reset(); $GLOBALS['ups_response'] = $response; $result = $validate( $base );
		$assert( 'unavailable' === $result['status'] && array() === $result['candidates'], 'Malformed, failed or contradictory provider responses never validate or suggest.' );
	}
	$reset(); $GLOBALS['ups_response'] = array( 'XAVResponse' => array( 'Response' => array( 'ResponseStatus' => array( 'Code' => '1' ) ), 'NoCandidatesIndicator' => '' ) );
	$assert( 'unmatched' === $validate( $base )['status'], 'A valid no-candidates response is an unmatched address.' );
	$reset(); $changed = array_merge( $base, array( 'city' => 'Purcellville' ) ); $GLOBALS['ups_response'] = $raw( $changed ); $result = $validate( $base );
	$assert( 'suggested' === $result['status'] && $changed === $result['candidates'][0], 'A valid indicator with corrected fields requires staff review.' );
	$reset(); $GLOBALS['ups_response'] = $raw( $base, 'AmbiguousAddressIndicator' );
	$assert( 'suggested' === $validate( $base )['status'], 'An ambiguous indicator never becomes valid merely because a candidate matches.' );
	$reset(); $GLOBALS['ups_response'] = $raw( array_merge( $base, array( 'address_1' => 'PO Box 789' ) ) ); $result = $validate( $base );
	$assert( 'unmatched' === $result['status'] && array() === $result['candidates'], 'A returned PO box cannot be offered as a usable suggestion.' );
	$reset(); $GLOBALS['ups_response']['XAVResponse']['Candidate'][0]['AddressKeyFormat']['PostcodeExtendedLow'] = '1234'; $result = $validate( $base );
	$assert( 'suggested' === $result['status'] && '20175-1234' === $result['candidates'][0]['postcode'], 'A provider ZIP extension is retained for explicit review.' );
	$reset(); $with_unit = array_merge( $base, array( 'address_2' => 'Suite 2' ) ); $result = $validate( $with_unit );
	$assert( 'suggested' === $result['status'] && 'Suite 2' === $result['candidates'][0]['address_2'] && str_contains( $result['message'], 'did not confirm' ), 'A dropped unit is preserved with a warning and cannot be marked valid.' );
	$reset(); $GLOBALS['ups_response'] = $raw( $with_unit );
	$assert( 'valid' === $validate( $with_unit )['status'], 'A confirmed second address line can match normally.' );
	$reset(); $single = $raw( $changed ); $single['XAVResponse']['Candidate'] = $single['XAVResponse']['Candidate'][0]; $GLOBALS['ups_response'] = $single;
	$assert( 'suggested' === $validate( $base )['status'], 'A single object-form candidate is interpreted safely.' );
	$reset(); $many = $raw( $base, 'AmbiguousAddressIndicator' );
	for ( $i = 1; $i < 20; $i++ ) { $next = $raw( array_merge( $base, array( 'address_1' => $i . ' Main St' ) ) ); $many['XAVResponse']['Candidate'][] = $next['XAVResponse']['Candidate'][0]; }
	$GLOBALS['ups_response'] = $many; $result = $validate( $base );
	$assert( 'suggested' === $result['status'] && 15 === count( $result['candidates'] ), 'Multiple candidate lists are bounded for the review UI.' );
	$reset(); $pr = array_merge( $base, array( 'city' => 'San Juan', 'state' => '', 'postcode' => '00901', 'country' => 'PR' ) ); $GLOBALS['ups_response'] = $raw( array_merge( $pr, array( 'state' => 'PR', 'country' => 'US' ) ) ); $result = $validate( $pr );
	$assert( 'valid' === $result['status'] && 'PR' === $GLOBALS['ups_package']['destination']['country'] && 'PR' === $GLOBALS['ups_validator_address']['state'], 'Puerto Rico keeps Woo zone country while the native validator receives its required state.' );
	$reset(); $GLOBALS['ups_response'] = $raw( array_merge( $pr, array( 'state' => 'PR', 'country' => 'US', 'address_1' => '12 Calle Principal' ) ) ); $result = $validate( $pr );
	$assert( 'suggested' === $result['status'] && 'PR' === $result['candidates'][0]['country'], 'UPS US/PR candidates preserve the submitted Woo Puerto Rico country.' );
	$reset(); $GLOBALS['ups_response'] = $raw( array_merge( $pr, array( 'state' => 'VA' ) ) );
	$assert( 'unavailable' === $validate( $pr )['status'], 'An inconsistent returned Puerto Rico state cannot be silently normalized to a valid match.' );
	echo 'Cart Cat UPS address validation contract: ' . $count . " assertions passed.\n";
}
