<?php
/**
 * Privacy boundary tests for support exports.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

/**
 * Exercises every free-form string that crosses the export privacy boundary.
 */
final class OGSMI_Redactor_Privacy_Test extends TestCase {

	/**
	 * Redact one representative private value without discarding generic text.
	 *
	 * @dataProvider sensitive_text_provider
	 *
	 * @param string $source Source text.
	 * @param string $secret Private substring which must disappear.
	 * @param string $token  Expected explicit placeholder.
	 */
	public function test_sensitive_text_variants_are_removed( $source, $secret, $token ) {
		$report                = $this->base_report();
		$report['sections'][0] = array(
			'id'     => 'environment',
			'title'  => 'Environment',
			'checks' => array(
				array(
					'id'             => 'php_version',
					'status'         => 'warning',
					'label'          => 'Compatibility detail',
					'value'          => $source,
					'message'        => 'Generic migration guidance remains available.',
					'recommendation' => 'Review this result.',
				),
			),
		);

		$check = OGSMI_Redactor::for_export( $report )['sections'][0]['checks'][0];

		$this->assertStringNotContainsString( $secret, $check['value'] );
		$this->assertStringContainsString( $token, $check['value'] );
		$this->assertSame( 'Generic migration guidance remains available.', $check['message'] );
	}

	/**
	 * Sensitive network and path formats accepted by free-form report fields.
	 *
	 * @return array
	 */
	public function sensitive_text_provider() {
		return array(
			'absolute URL'          => array(
				'Endpoint https://private.example.test/wp-admin?scan=1 failed.',
				'https://private.example.test/wp-admin?scan=1',
				'[redacted-url]',
			),
			'protocol-relative URL' => array(
				'Endpoint //private.example.test/wp-json failed.',
				'//private.example.test/wp-json',
				'[redacted-url]',
			),
			'bare domain'           => array(
				'Host private.example.test is unavailable.',
				'private.example.test',
				'[redacted-domain]',
			),
			'email address'         => array(
				'Contact migration-owner@private.example.test for access.',
				'migration-owner@private.example.test',
				'[redacted-email]',
			),
			'IPv4 with port'        => array(
				'Source 203.0.113.42:8443 is unavailable.',
				'203.0.113.42',
				'[redacted-ip]',
			),
			'full IPv6'             => array(
				'Source 2001:db8:85a3::8a2e:370:7334 is unavailable.',
				'2001:db8:85a3::8a2e:370:7334',
				'[redacted-ip]',
			),
			'bracketed IPv6'        => array(
				'Source [2001:db8::7]:443 is unavailable.',
				'2001:db8::7',
				'[redacted-ip]',
			),
			'scoped IPv6'           => array(
				'Source fe80::1%eth0 is unavailable.',
				'fe80::1%eth0',
				'[redacted-ip]',
			),
			'IPv4-mapped IPv6'      => array(
				'Source ::ffff:192.0.2.128 is unavailable.',
				'::ffff:192.0.2.128',
				'[redacted-ip]',
			),
			'Windows drive path'    => array(
				'Path C:\\Users\\Customer\\Sites\\private-site, cannot be read.',
				'C:\\Users\\Customer\\Sites\\private-site',
				'[redacted-path]',
			),
			'Windows slash path'    => array(
				'Path D:/Sites/Customer/private-site, cannot be read.',
				'D:/Sites/Customer/private-site',
				'[redacted-path]',
			),
			'Windows path spaces'   => array(
				'Path C:\\Users\\Jane Doe\\Private Site\\config.php, cannot be read.',
				'C:\\Users\\Jane Doe\\Private Site\\config.php',
				'[redacted-path]',
			),
			'Windows device path'   => array(
				'Path \\\\?\\C:\\Users\\Customer\\private-site, cannot be read.',
				'\\\\?\\C:\\Users\\Customer\\private-site',
				'[redacted-path]',
			),
			'Windows UNC path'      => array(
				'Path \\\\fileserver\\customers\\private-site, cannot be read.',
				'\\\\fileserver\\customers\\private-site',
				'[redacted-path]',
			),
			'Unix absolute path'    => array(
				'Path /srv/www/customer/private-site, cannot be read.',
				'/srv/www/customer/private-site',
				'[redacted-path]',
			),
			'Unix path spaces'      => array(
				'Path /srv/www/Private Site/current, cannot be read.',
				'/srv/www/Private Site/current',
				'[redacted-path]',
			),
			'double-slash path'     => array(
				'Path //fileserver/private/site, cannot be read.',
				'//fileserver/private/site',
				'[redacted-path]',
			),
		);
	}

	/**
	 * Apply filtering consistently to check text, titles, and partial reasons.
	 */
	public function test_all_free_form_check_fields_are_filtered_without_losing_generic_guidance() {
		$report                    = $this->base_report();
		$report['partial_reasons'] = array(
			'Connection to 2001:db8::25 failed during the safe scan.',
		);
		$report['sections']        = array(
			array(
				'id'     => 'configuration',
				'title'  => 'Review private.example.test configuration',
				'checks' => array(
					array(
						'id'             => 'custom_paths',
						'status'         => 'warning',
						'label'          => 'Review https://private.example.test/settings now',
						'value'          => 'Owner admin@private.example.test uses 203.0.113.42',
						'message'        => 'IPv6 [2001:db8::25]:443 and path /srv/www/customer, require review.',
						'recommendation' => 'Copy C:\\Sites\\Customer\\private-site; then inspect \\\\fileserver\\private\\site',
					),
				),
			),
		);

		$export     = OGSMI_Redactor::for_export( $report );
		$serialized = wp_json_encode( $export );
		$check      = $export['sections'][0]['checks'][0];

		foreach (
			array(
				'private.example.test',
				'admin@private.example.test',
				'203.0.113.42',
				'2001:db8::25',
				'/srv/www/customer',
				'C:\\Sites\\Customer\\private-site',
				'\\\\fileserver\\private\\site',
			) as $private_value
		) {
			$this->assertStringNotContainsString( $private_value, $serialized );
		}

		$this->assertSame( 'Review [redacted-url] now', $check['label'] );
		$this->assertSame( 'Owner [redacted-email] uses [redacted-ip]', $check['value'] );
		$this->assertStringContainsString( 'require review', $check['message'] );
		$this->assertStringContainsString( 'then inspect', $check['recommendation'] );
		$this->assertStringContainsString( '[redacted-ip]', $export['partial_reasons'][0] );
	}

	/**
	 * Treat component header names as opaque even when copied into check prose.
	 */
	public function test_plugin_and_theme_names_are_opaque_everywhere() {
		$report                          = $this->base_report();
		$report['inventory']['software'] = array(
			'wordpress_version'    => '6.8.2',
			'php_version'          => '8.2.20',
			'core_required_php'    => '7.4',
			'strictest_php'        => '8.3',
			'php_requirement_from' => 'Acme Customer Bridge',
			'active_plugins'       => array(
				array(
					'name'         => 'Acme Customer Bridge',
					'version'      => '4.5.6',
					'requires_php' => '8.3',
					'requires_wp'  => '6.5',
					'network'      => true,
					'private_uri'  => 'https://private.example.test/plugin',
				),
			),
			'active_theme'         => array(
				'name'         => 'Northstar Bespoke Theme',
				'version'      => '2.3.4',
				'requires_php' => '8.1',
				'requires_wp'  => '6.4',
				'theme_uri'    => 'https://private.example.test/theme',
			),
		);
		$report['sections']              = array(
			array(
				'id'     => 'environment',
				'title'  => 'Northstar environment',
				'checks' => array(
					array(
						'id'             => 'php_version',
						'status'         => 'warning',
						'label'          => 'Acme compatibility',
						'value'          => 'PHP 8.3 required',
						'message'        => 'Acme Customer Bridge requires PHP 8.3 for Northstar.',
						'recommendation' => 'Review generic compatibility guidance.',
					),
				),
			),
		);

		$export     = OGSMI_Redactor::for_export( $report );
		$software   = $export['inventory']['software'];
		$serialized = wp_json_encode( $export );

		foreach ( array( 'Acme', 'Acme Customer Bridge', 'Northstar', 'Northstar Bespoke Theme' ) as $identifier ) {
			$this->assertStringNotContainsString( $identifier, $serialized );
		}

		$this->assertSame( '[redacted-plugin-name]', $software['active_plugins'][0]['name'] );
		$this->assertSame( '[redacted-theme-name]', $software['active_theme']['name'] );
		$this->assertSame( '[redacted-site-identifier]', $software['php_requirement_from'] );
		$this->assertSame( '4.5.6', $software['active_plugins'][0]['version'] );
		$this->assertSame( '8.3', $software['active_plugins'][0]['requires_php'] );
		$this->assertTrue( $software['active_plugins'][0]['network'] );
		$this->assertStringContainsString(
			'requires PHP 8.3',
			$export['sections'][0]['checks'][0]['message']
		);
	}

	/**
	 * Reject undeclared keys and strict-format metadata outside the schema.
	 */
	public function test_export_keeps_a_stable_allowlist_schema() {
		$report                       = $this->base_report();
		$report['private_root_value'] = 'https://private.example.test';
		$report['schema_version']     = 'https://private.example.test/schema';
		$report['plugin_version']     = 'admin@private.example.test';
		$report['generated_at']       = '/srv/www/private/generated';
		$report['sections']           = array(
			array(
				'id'           => 'environment',
				'title'        => 'Environment',
				'private_meta' => 'private.example.test',
				'checks'       => array(
					array(
						'id'             => 'wordpress_version',
						'status'         => 'pass',
						'label'          => 'WordPress version',
						'value'          => '6.8.2',
						'message'        => 'Version metadata retained.',
						'recommendation' => '',
						'debug'          => 'C:\\private\\debug.log',
					),
				),
			),
		);
		$report['inventory']['software']['active_plugins'] = array(
			array(
				'name'         => 'Private Plugin',
				'version'      => '1.2.3',
				'requires_php' => '8.1',
				'requires_wp'  => '6.2',
				'network'      => false,
				'path'         => '/srv/www/private/plugin.php',
			),
		);

		$export = OGSMI_Redactor::for_export( $report );

		$this->assertSame(
			array(
				'schema_version',
				'plugin_version',
				'generated_at',
				'scope',
				'partial',
				'partial_reasons',
				'destination',
				'summary',
				'sections',
				'inventory',
			),
			array_keys( $export )
		);
		$this->assertSame(
			array( 'id', 'status', 'label', 'value', 'message', 'recommendation' ),
			array_keys( $export['sections'][0]['checks'][0] )
		);
		$this->assertSame(
			array( 'name', 'version', 'requires_php', 'requires_wp', 'network' ),
			array_keys( $export['inventory']['software']['active_plugins'][0] )
		);
		$this->assertSame( '1.0', $export['schema_version'] );
		$this->assertSame( '', $export['plugin_version'] );
		$this->assertSame( '', $export['generated_at'] );
		$this->assertSame( '6.8.2', $export['sections'][0]['checks'][0]['value'] );
		$this->assertArrayNotHasKey( 'private_root_value', $export );
	}

	/**
	 * Minimal valid internal report.
	 *
	 * @return array
	 */
	private function base_report() {
		return array(
			'schema_version'  => '1.0',
			'plugin_version'  => '1.0.0',
			'generated_at'    => '2026-07-27T00:00:00+00:00',
			'scope'           => 'site',
			'partial'         => false,
			'partial_reasons' => array(),
			'destination'     => array(),
			'summary'         => array(
				'overall' => 'review_recommended',
				'counts'  => array(),
			),
			'sections'        => array(),
			'inventory'       => array(
				'files'    => array(),
				'database' => array(),
				'software' => array(),
			),
		);
	}
}
