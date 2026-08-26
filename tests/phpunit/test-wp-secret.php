<?php
/**
 * Tests for WP_Secret.
 *
 * @package SecretsAPI
 */

/**
 * Masking, serialization refusal, and vault lifecycle for WP_Secret.
 *
 * @group secrets
 */
class Tests_Secrets_WPSecret extends WP_UnitTestCase {

	const PLAINTEXT   = 'sk_live_super_secret_value';
	const NAME        = 'myplugin/api-key';
	const FINGERPRINT = 'abc123def456';

	private function make_secret() {
		return new WP_Secret( self::NAME, self::PLAINTEXT, self::FINGERPRINT );
	}

	public function test_class_is_final() {
		$reflection = new ReflectionClass( WP_Secret::class );

		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_implements_json_serializable() {
		$this->assertInstanceOf( JsonSerializable::class, $this->make_secret() );
	}

	public function test_reveal_returns_the_exact_plaintext() {
		$secret = $this->make_secret();

		$this->assertSame( self::PLAINTEXT, $secret->reveal() );
	}

	public function test_fingerprint_returns_the_exact_fingerprint() {
		$secret = $this->make_secret();

		$this->assertSame( self::FINGERPRINT, $secret->fingerprint() );
	}

	public function test_get_name_returns_the_exact_name() {
		$secret = $this->make_secret();

		$this->assertSame( self::NAME, $secret->get_name() );
	}

	public function test_constructor_rejects_a_non_string_value() {
		$this->expectException( InvalidArgumentException::class );

		new WP_Secret( self::NAME, 12345, self::FINGERPRINT );
	}

	public function test_constructor_rejects_an_empty_name() {
		$this->expectException( InvalidArgumentException::class );

		new WP_Secret( '', self::PLAINTEXT, self::FINGERPRINT );
	}

	public function test_constructor_rejects_an_empty_fingerprint() {
		$this->expectException( InvalidArgumentException::class );

		new WP_Secret( self::NAME, self::PLAINTEXT, '' );
	}

	/**
	 * Every representation short of reveal() must exclude the plaintext.
	 *
	 * @dataProvider data_masking_surfaces
	 */
	public function test_masking_surfaces_never_contain_the_plaintext( $callback ) {
		$secret = $this->make_secret();

		$output = $callback( $secret );

		$this->assertStringNotContainsString( self::PLAINTEXT, $output );
	}

	public function data_masking_surfaces() {
		return array(
			'(string) cast'        => array(
				function ( $secret ) {
					return (string) $secret;
				},
			),
			'string interpolation' => array(
				function ( $secret ) {
					return "{$secret}";
				},
			),
			'json_encode'          => array(
				function ( $secret ) {
					return json_encode( $secret );
				},
			),
			'var_dump'             => array(
				function ( $secret ) {
					ob_start();
					var_dump( $secret );

					return ob_get_clean();
				},
			),
			'print_r'              => array(
				function ( $secret ) {
					return print_r( $secret, true );
				},
			),
			'var_export'           => array(
				function ( $secret ) {
					return var_export( $secret, true );
				},
			),
			'error_log'            => array(
				function ( $secret ) {
					$file = tempnam( sys_get_temp_dir(), 'wp-secret-test-' );
					error_log( $secret, 3, $file );
					$contents = file_get_contents( $file );
					unlink( $file );

					return $contents;
				},
			),
		);
	}

	public function test_to_string_yields_the_masked_placeholder() {
		$secret = $this->make_secret();

		$this->assertSame( '[secret:' . self::NAME . ']', (string) $secret );
	}

	public function test_json_encode_yields_the_masked_placeholder() {
		$secret = $this->make_secret();

		// json_encode() escapes '/' by default; decode rather than compare raw JSON.
		$this->assertSame( '[secret:' . self::NAME . ']', json_decode( json_encode( $secret ) ) );
	}

	public function test_var_dump_yields_the_masked_placeholder() {
		$secret = $this->make_secret();

		ob_start();
		var_dump( $secret );
		$output = ob_get_clean();

		$this->assertStringContainsString( '[secret:' . self::NAME . ']', $output );
	}

	public function test_serialize_throws() {
		$secret = $this->make_secret();

		$this->expectException( LogicException::class );

		serialize( $secret );
	}

	public function test_clone_throws() {
		$secret = $this->make_secret();

		$this->expectException( LogicException::class );

		clone $secret;
	}

	/**
	 * Invoked directly via reflection, rather than through serialize()/unserialize(),
	 * because __serialize() alone shadows __sleep() and vice versa depending on which
	 * pair PHP's engine prefers -- this proves each of the four refuses independently.
	 *
	 * @dataProvider data_refusing_magic_methods
	 */
	public function test_serialization_magic_methods_throw_directly( $method, $args ) {
		$secret     = $this->make_secret();
		$reflection = new ReflectionMethod( $secret, $method );
		$reflection->setAccessible( true );

		$this->expectException( LogicException::class );

		$reflection->invokeArgs( $secret, $args );
	}

	public function data_refusing_magic_methods() {
		return array(
			'__sleep'       => array( '__sleep', array() ),
			'__wakeup'      => array( '__wakeup', array() ),
			'__serialize'   => array( '__serialize', array() ),
			'__unserialize' => array( '__unserialize', array( array() ) ),
		);
	}

	public function test_destructing_the_last_reference_removes_it_from_the_vault() {
		$secret = $this->make_secret();
		$id     = spl_object_id( $secret );

		$vault_property = new ReflectionProperty( WP_Secret::class, 'vault' );
		$vault_property->setAccessible( true );

		$this->assertArrayHasKey( $id, $vault_property->getValue() );

		unset( $secret );

		$this->assertArrayNotHasKey( $id, $vault_property->getValue() );
	}

	public function test_two_instances_do_not_share_a_vault_slot() {
		$a = new WP_Secret( 'plugin/a', 'value-a', 'fp-a' );
		$b = new WP_Secret( 'plugin/b', 'value-b', 'fp-b' );

		$this->assertSame( 'value-a', $a->reveal() );
		$this->assertSame( 'value-b', $b->reveal() );

		unset( $a );

		$this->assertSame( 'value-b', $b->reveal() );
	}
}
