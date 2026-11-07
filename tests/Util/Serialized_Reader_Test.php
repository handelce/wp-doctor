<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Util;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Tests\Support\Wakeup_Probe;
use WP_Doctor\Util\Malformed_Serialization_Exception;
use WP_Doctor\Util\Serialized_Reader;

final class Serialized_Reader_Test extends TestCase {

	public function test_an_array_is_walked_key_by_key(): void {
		$reader = new Serialized_Reader(
			serialize(
				array(
					'version'  => 2,
					1700000000 => array( 'a' => 'b' ),
				)
			)
		);

		$this->assertSame( 2, $reader->open_array() );
		$this->assertSame( 'version', $reader->read_key() );
		$reader->skip_value();
		$this->assertSame( 1700000000, $reader->read_key() );
		$this->assertSame( 1, $reader->open_array() );
		$this->assertSame( 'a', $reader->read_key() );
		$reader->skip_value();
		$reader->close_array();
		$reader->close_array();
		$reader->assert_end();
	}

	/**
	 * @dataProvider values_php_can_serialize
	 * @param mixed $value A value built only from arrays and scalars.
	 */
	public function test_every_value_php_serializes_can_be_skipped( $value ): void {
		$reader = new Serialized_Reader( serialize( $value ) );

		$reader->skip_value();
		$reader->assert_end();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function values_php_can_serialize(): array {
		return array(
			'null'           => array( null ),
			'true'           => array( true ),
			'false'          => array( false ),
			'zero'           => array( 0 ),
			'negative'       => array( -42 ),
			'float'          => array( 1.5 ),
			'small float'    => array( 1.0E-10 ),
			'infinity'       => array( INF ),
			'not a number'   => array( NAN ),
			'empty string'   => array( '' ),
			'quotes in text' => array( 'a";b:1;' ),
			'multibyte text' => array( "caf\xC3\xA9" ),
			'empty array'    => array( array() ),
			'nested'         => array(
				array(
					'schedule' => 'hourly',
					'args'     => array( 1, 'two', array( 3.5 ) ),
					'interval' => 3600,
				),
			),
		);
	}

	public function test_the_value_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->values_php_can_serialize() );
	}

	/**
	 * @dataProvider malformed_input
	 * @param string $data Input that is not a plain serialized array or scalar.
	 */
	public function test_input_that_is_not_plain_data_is_refused( string $data ): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		$reader = new Serialized_Reader( $data );
		$reader->skip_value();
		$reader->assert_end();
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function malformed_input(): array {
		return array(
			'object'                  => array( 'O:8:"stdClass":0:{}' ),
			'object inside an array'  => array( 'a:1:{i:0;O:8:"stdClass":0:{}}' ),
			'serializable object'     => array( 'C:11:"ArrayObject":0:{}' ),
			'enum'                    => array( 'E:7:"Foo:Bar";' ),
			'back reference'          => array( 'a:2:{i:0;a:0:{}i:1;r:2;}' ),
			'escaped string'          => array( 'S:1:"\61";' ),
			'empty input'             => array( '' ),
			'unknown type'            => array( 'x:1;' ),
			'truncated array'         => array( 'a:1:{i:0;s:1:"a";' ),
			'too few entries'         => array( 'a:2:{i:0;i:1;}' ),
			'too many entries'        => array( 'a:1:{i:0;i:1;i:1;i:2;}' ),
			'string too short'        => array( 's:5:"abc";' ),
			'string too long'         => array( 's:1:"abc";' ),
			'string without a close'  => array( 's:3:"abc"' ),
			'string with a wrong end' => array( 's:3:"abcXY' ),
			'array key that is bool'  => array( 'a:1:{b:1;}' ),
			'array key that is float' => array( 'a:1:{d:1.5;i:1;}' ),
			'huge string length'      => array( 's:99999999999:"abc";' ),
			'negative string length'  => array( 's:-1:"";' ),
			'integer without a semi'  => array( 'i:5' ),
			'integer with text'       => array( 'i:five;' ),
			'bool out of range'       => array( 'b:2;' ),
			'trailing data'           => array( 'i:1;i:2;' ),
			'trailing byte'           => array( 'i:1;x' ),
			'array key that is array' => array( 'a:1:{a:0:{}i:1;}' ),
			'array key that is null'  => array( 'a:1:{N;i:1;}' ),
			'nesting too deep'        => array( str_repeat( 'a:1:{i:0;', 200 ) . 'i:1;' . str_repeat( '}', 200 ) ),
		);
	}

	public function test_the_malformed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_input() );
	}

	public function test_reading_a_key_where_a_close_is_expected_is_refused(): void {
		$reader = new Serialized_Reader( 'a:1:{i:0;i:1;}' );
		$reader->open_array();
		$reader->read_key();
		$reader->skip_value();
		$reader->close_array();

		$this->expectException( Malformed_Serialization_Exception::class );
		$reader->close_array();
	}

	public function test_closing_an_array_that_was_never_opened_is_refused(): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		( new Serialized_Reader( '}' ) )->close_array();
	}

	public function test_a_key_that_is_not_an_integer_or_string_is_refused(): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		( new Serialized_Reader( 'b:1;' ) )->read_key();
	}

	public function test_opening_a_scalar_as_an_array_is_refused(): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		( new Serialized_Reader( 'i:1;' ) )->open_array();
	}

	public function test_an_error_message_does_not_contain_the_input(): void {
		try {
			( new Serialized_Reader( 'x:secret-looking-value;' ) )->skip_value();
			$this->fail( 'No exception' );
		} catch ( Malformed_Serialization_Exception $e ) {
			$this->assertStringNotContainsString( 'secret-looking-value', $e->getMessage() );
		}
	}

	public function test_objects_are_refused_by_default_even_when_they_could_be_walked(): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		( new Serialized_Reader( 'O:8:"stdClass":1:{s:1:"a";i:1;}' ) )->open_array();
	}

	public function test_an_object_is_walked_as_a_list_of_properties_when_objects_are_allowed(): void {
		$object      = new \stdClass();
		$object->a   = 'first';
		$object->b   = array( 1, 2 );
		$object->new = 'last';
		$reader      = new Serialized_Reader( serialize( $object ), true );

		$this->assertSame( 3, $reader->open_array() );
		$this->assertSame( 'a', $reader->read_key() );
		$this->assertSame( 'first', $reader->read_text_or_skip() );
		$this->assertSame( 'b', $reader->read_key() );
		$this->assertNull( $reader->read_text_or_skip() );
		$this->assertSame( 'new', $reader->read_key() );
		$this->assertSame( 'last', $reader->read_text_or_skip() );
		$reader->close_array();
		$reader->assert_end();
	}

	public function test_a_named_class_is_never_instantiated(): void {
		$name = Wakeup_Probe::class;
		$data = 'O:' . strlen( $name ) . ':"' . $name . '":1:{s:1:"a";i:1;}';

		Wakeup_Probe::$woken = false;
		unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Shows the probe is woken by unserialize().
		$this->assertTrue( Wakeup_Probe::$woken, 'The probe class is not woken by unserialize(), so this test proves nothing' );

		Wakeup_Probe::$woken = false;
		$reader              = new Serialized_Reader( $data, true );
		$reader->skip_value();
		$reader->assert_end();

		$this->assertFalse( Wakeup_Probe::$woken );
	}

	/**
	 * @dataProvider malformed_objects
	 * @param string $data Input that is not a readable object.
	 */
	public function test_a_malformed_object_is_refused_even_when_objects_are_allowed( string $data ): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		$reader = new Serialized_Reader( $data, true );
		$reader->skip_value();
		$reader->assert_end();
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function malformed_objects(): array {
		return array(
			'class name too short'          => array( 'O:9:"stdClass":0:{}' ),
			'class name too long'           => array( 'O:2:"stdClass":0:{}' ),
			'class name with a quote'       => array( 'O:3:"a"b":0:{}' ),
			'class name with a digit first' => array( 'O:3:"1ab":0:{}' ),
			'no property count'             => array( 'O:8:"stdClass"{}' ),
			'serializable object'           => array( 'C:11:"ArrayObject":0:{}' ),
			'too few properties'            => array( 'O:8:"stdClass":2:{s:1:"a";i:1;}' ),
			'nested too deep'               => array( str_repeat( 'O:8:"stdClass":1:{s:1:"a";', 200 ) . 'i:1;' . str_repeat( '}', 200 ) ),
		);
	}

	public function test_the_malformed_object_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_objects() );
	}

	public function test_a_namespaced_class_name_is_accepted(): void {
		$name   = 'Example\\Thing_One';
		$reader = new Serialized_Reader( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}', true );

		$reader->skip_value();
		$reader->assert_end();
		$this->addToAssertionCount( 1 );
	}

	public function test_the_largest_and_smallest_integer_keys_are_read(): void {
		$reader = new Serialized_Reader(
			serialize(
				array(
					PHP_INT_MAX => 1,
					PHP_INT_MIN => 2,
				)
			)
		);

		$this->assertSame( 2, $reader->open_array() );
		$this->assertSame( PHP_INT_MAX, $reader->read_key() );
		$reader->skip_value();
		$this->assertSame( PHP_INT_MIN, $reader->read_key() );
		$reader->skip_value();
		$reader->close_array();
		$reader->assert_end();
	}

	public function test_a_plain_array_is_required_where_one_is_asked_for_even_when_objects_are_allowed(): void {
		$reader = new Serialized_Reader( 'a:1:{i:0;O:8:"stdClass":0:{}}', true );

		$this->assertSame( 1, $reader->open_plain_array() );
		$reader->read_key();
		$this->expectException( Malformed_Serialization_Exception::class );
		$reader->open_plain_array();
	}

	/**
	 * @dataProvider back_references
	 * @param string $data Serialized value holding a back-reference.
	 */
	public function test_a_back_reference_is_skipped_when_objects_are_allowed( string $data ): void {
		$reader = new Serialized_Reader( $data, true );

		$reader->skip_value();
		$reader->assert_end();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function back_references(): array {
		$object = new \stdClass();
		$array  = array( 1 );

		return array(
			'repeated object'  => array( serialize( array( $object, $object ) ) ),
			'reference to var' => array( serialize( array( &$array, &$array ) ) ),
		);
	}

	public function test_the_back_reference_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->back_references() );
	}

	/**
	 * @dataProvider malformed_references
	 * @param string $data A reference that is not a positive index.
	 */
	public function test_a_malformed_back_reference_is_refused_even_when_objects_are_allowed( string $data ): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		$reader = new Serialized_Reader( $data, true );
		$reader->skip_value();
		$reader->assert_end();
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function malformed_references(): array {
		return array(
			'zero'         => array( 'a:1:{i:0;r:0;}' ),
			'negative'     => array( 'a:1:{i:0;r:-1;}' ),
			'too many'     => array( 'a:1:{i:0;r:12345678901234567890;}' ),
			'no semicolon' => array( 'a:1:{i:0;R:2}' ),
			'as an array'  => array( 'r:1;' ),
		);
	}

	public function test_the_malformed_reference_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_references() );
	}
}
