<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Advisory address checks through the same UPS integration as Woo checkout. */
final class Address_Validation {
	private const FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
	private const UNAVAILABLE = 'UPS address validation is unavailable right now. Your address has not been changed. Try again later or review it manually.';

	public static function validate( $input ) {
		$address = self::address( $input );
		if ( is_wp_error( $address ) ) { return $address; }
		if ( ! in_array( $address['country'], array( 'US', 'PR' ), true ) ) {
			return self::result( 'unsupported', 'UPS address validation supports United States and Puerto Rico addresses. Review this address manually.' );
		}
		try {
			if ( ! class_exists( '\\WC_Shipping_Zones' ) ) { return self::result( 'unavailable', self::UNAVAILABLE ); }
			$zone = \WC_Shipping_Zones::get_zone_matching_package( array( 'destination' => $address ) );
			$method = null;
			foreach ( $zone->get_shipping_methods( true ) as $candidate ) {
				if ( ! $candidate instanceof \WC_Shipping_UPS || 'ups' !== $candidate->id || 'yes' !== $candidate->enabled ) { continue; }
				if ( 'rest' !== $candidate->get_api_type() || ! $candidate->is_destination_address_validation_enabled() || ! $candidate->country_supports_address_validation( $address['country'] ) ) { continue; }
				$method = $candidate;
				break;
			}
			$validator_class = '\\WooCommerce\\UPS\\API\\REST\\Address_Validator';
			if ( ! $method || ! class_exists( $validator_class ) ) { return self::result( 'unavailable', self::UNAVAILABLE ); }
			// The native constructor only copies postal fields and normalizes Puerto Rico.
			$validator = new $validator_class( $address, '' );
			if ( $validator->is_po_box() ) { return self::result( 'unmatched', 'UPS cannot deliver to this PO Box. Enter a street address and validate again.' ); }
			$token = $method->get_ups_oauth()->get_access_token();
			if ( ! is_string( $token ) || '' === trim( $token ) ) { return self::result( 'unavailable', self::UNAVAILABLE ); }
			$validator = new $validator_class( $address, $token );
			$validator->validate();
			return self::response( $validator->get_response(), $address );
		} catch ( \Throwable $error ) {
			// Provider diagnostics can contain credentials or addresses. Never expose them.
			return self::result( 'unavailable', self::UNAVAILABLE );
		}
	}

	private static function address( $input ) {
		$error = new \WP_Error( 'cart_cat_address_incomplete', 'Enter a complete shipping street address, city, state, ZIP/postcode and country before validating with UPS.', array( 'status' => 400 ) );
		if ( ! is_array( $input ) ) { return $error; }
		$address = array();
		foreach ( self::FIELDS as $field ) {
			$value = $input[ $field ] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 200 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) { return $error; }
			$address[ $field ] = trim( sanitize_text_field( $value ) );
			if ( 'address_2' !== $field && 'state' !== $field && '' === $address[ $field ] ) { return $error; }
		}
		$address['country'] = strtoupper( $address['country'] );
		$address['state'] = strtoupper( $address['state'] );
		if ( ! preg_match( '/^[A-Z]{2}$/', $address['country'] ) ) { return $error; }
		if ( 'PR' === $address['country'] ) { $address['state'] = 'PR'; }
		if ( in_array( $address['country'], array( 'US', 'PR' ), true ) && ( ! preg_match( '/^[A-Z]{2}$/', $address['state'] ) || ! preg_match( '/^\d{5}(?:-\d{4})?$/', $address['postcode'] ) ) ) { return $error; }
		return $address;
	}

	/** Interpret indicators and candidates ourselves; the native match helper accepts malformed arrays. */
	private static function response( $response, array $address ) {
		$unavailable = self::result( 'unavailable', self::UNAVAILABLE );
		if ( ! is_array( $response ) || ! isset( $response['XAVResponse'] ) || ! is_array( $response['XAVResponse'] ) ) { return $unavailable; }
		$data = $response['XAVResponse'];
		$code = $data['Response']['ResponseStatus']['Code'] ?? null;
		if ( '1' !== $code && 1 !== $code ) { return $unavailable; }
		$indicators = array();
		foreach ( array( 'ValidAddressIndicator', 'AmbiguousAddressIndicator', 'NoCandidatesIndicator' ) as $indicator ) {
			if ( array_key_exists( $indicator, $data ) ) {
				if ( ! is_string( $data[ $indicator ] ) ) { return $unavailable; }
				$indicators[] = $indicator;
			}
		}
		if ( 1 !== count( $indicators ) ) { return $unavailable; }
		if ( 'NoCandidatesIndicator' === $indicators[0] ) {
			if ( ! empty( $data['Candidate'] ) ) { return $unavailable; }
			return self::result( 'unmatched', 'UPS could not find a matching address. Check the street, apartment or suite, city and ZIP code.' );
		}
		$raw_candidates = $data['Candidate'] ?? null;
		if ( ! is_array( $raw_candidates ) || ! $raw_candidates ) { return $unavailable; }
		if ( isset( $raw_candidates['AddressKeyFormat'] ) ) { $raw_candidates = array( $raw_candidates ); }
		if ( ! array_is_list( $raw_candidates ) ) { return $unavailable; }
		$candidates = array();
		$unit_missing = false;
		foreach ( array_slice( $raw_candidates, 0, 15 ) as $raw ) {
			$candidate = self::candidate( $raw, $address );
			if ( ! $candidate ) { return $unavailable; }
			$candidate_validator = new \WooCommerce\UPS\API\REST\Address_Validator( $candidate, '' );
			if ( $candidate_validator->is_po_box() ) { return self::result( 'unmatched', 'UPS returned a PO Box, which UPS cannot deliver to. Enter a street address and validate again.' ); }
			if ( '' !== $address['address_2'] && '' === $candidate['address_2'] ) {
				// Never erase an apartment or suite that UPS did not return.
				$candidate['address_2'] = $address['address_2'];
				$unit_missing = true;
			}
			$candidates[ wp_json_encode( self::normalized( $candidate ) ) ] = $candidate;
		}
		$candidates = array_values( $candidates );
		if ( 'ValidAddressIndicator' === $indicators[0] && 1 === count( $candidates ) && ! $unit_missing && self::normalized( $address ) === self::normalized( $candidates[0] ) ) {
			return self::result( 'valid', 'UPS matched this address. Apartment or suite details and delivery eligibility still need to be correct.' );
		}
		$message = 'UPS returned a suggested address. Review it before using it.';
		if ( count( $candidates ) > 1 ) { $message = 'UPS returned several possible addresses. Select the correct address after reviewing the suggestions.'; }
		if ( $unit_missing ) { $message .= ' UPS did not confirm the apartment or suite; your entered second address line has been kept for review.'; }
		return self::result( 'suggested', $message, $candidates );
	}

	private static function candidate( $raw, array $submitted ) {
		if ( ! is_array( $raw ) || ! isset( $raw['AddressKeyFormat'] ) || ! is_array( $raw['AddressKeyFormat'] ) ) { return null; }
		$raw = $raw['AddressKeyFormat'];
		$lines = $raw['AddressLine'] ?? null;
		if ( is_string( $lines ) ) { $lines = array( $lines ); }
		if ( ! is_array( $lines ) || ! array_is_list( $lines ) || ! $lines || count( $lines ) > 3 ) { return null; }
		foreach ( $lines as $line ) { if ( ! is_string( $line ) ) { return null; } }
		$postcode = $raw['PostcodePrimaryLow'] ?? null;
		$extension = $raw['PostcodeExtendedLow'] ?? '';
		if ( ! is_string( $postcode ) || ! is_string( $extension ) || ( '' !== $extension && ! preg_match( '/^\d{4}$/', $extension ) ) ) { return null; }
		if ( '' !== $extension ) {
			if ( preg_match( '/^\d{5}$/', $postcode ) ) { $postcode .= '-' . $extension; }
			elseif ( substr( $postcode, -5 ) !== '-' . $extension ) { return null; }
		}
		$candidate = array(
			'address_1' => $lines[0], 'address_2' => implode( ', ', array_slice( $lines, 1 ) ),
			'city' => $raw['PoliticalDivision2'] ?? null, 'state' => $raw['PoliticalDivision1'] ?? null,
			'postcode' => $postcode, 'country' => $raw['CountryCode'] ?? null,
		);
		if ( 'PR' === $submitted['country'] && 'PR' !== $candidate['state'] ) { return null; }
		// UPS represents Puerto Rico as US/PR; keep Woo's submitted country representation.
		if ( 'PR' === $submitted['country'] && in_array( $candidate['country'], array( 'US', 'PR' ), true ) && 'PR' === $candidate['state'] ) { $candidate['country'] = 'PR'; }
		if ( $candidate['country'] !== $submitted['country'] ) { return null; }
		$candidate = self::address( $candidate );
		if ( is_wp_error( $candidate ) ) { return null; }
		return $candidate;
	}

	private static function normalized( array $address ) {
		return array_map( static function ( $value ) { return strtolower( preg_replace( '/\s+/', ' ', trim( $value ) ) ); }, $address );
	}

	private static function result( $status, $message, array $candidates = array() ) {
		return array( 'status' => $status, 'message' => $message, 'candidates' => $candidates );
	}
}
