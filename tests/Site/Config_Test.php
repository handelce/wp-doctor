<?php
/**
 * Tests for Config.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Site;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Site\Config;
use WP_Doctor\Site\Config_Parser;

final class Config_Test extends TestCase {

	private function parse( string $body ): Config {
		return ( new Config_Parser() )->parse( "<?php\n" . $body );
	}

	public function test_nothing_is_skipped_when_every_needed_value_is_resolved(): void {
		$config = $this->parse( "define( 'DB_NAME', 'example_db' ); define( 'DB_USER', 'u' ); \$table_prefix = 'wp_';" );

		$this->assertNull( $config->skip_reason( 'DB_NAME', 'DB_USER', Config::TABLE_PREFIX ) );
	}

	public function test_the_skip_reason_names_each_unresolved_value_and_why(): void {
		$config = $this->parse( "define( 'DB_NAME', getenv( 'X' ) ); define( 'DB_USER', 'u' );" );

		$reason = $config->skip_reason( 'DB_NAME', 'DB_USER' );

		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'DB_NAME (computed value)', (string) $reason );
		$this->assertStringNotContainsString( 'DB_USER', (string) $reason );
	}

	public function test_the_skip_reason_says_when_a_value_is_not_defined(): void {
		$config = $this->parse( "define( 'DB_USER', 'u' );" );

		$this->assertSame(
			'wp-config.php values unavailable: DB_NAME (not defined)',
			$config->skip_reason( 'DB_NAME', 'DB_USER' )
		);
	}

	public function test_the_skip_reason_mentions_computed_names_and_unread_code(): void {
		$config = $this->parse( "define( \$n, 1 ); include 'local.php';" );

		$reason = (string) $config->skip_reason( 'DB_NAME' );

		$this->assertStringContainsString( '1 define() call with a computed name', $reason );
		$this->assertStringContainsString( '1 include not read', $reason );
	}

	public function test_a_value_that_is_not_resolved_cannot_be_read(): void {
		$this->expectException( \OutOfBoundsException::class );
		$this->parse( "define( 'DB_NAME', getenv( 'X' ) );" )->value( 'DB_NAME' );
	}

	public function test_a_table_prefix_that_is_not_resolved_cannot_be_read(): void {
		$this->expectException( \OutOfBoundsException::class );
		$this->parse( '' )->table_prefix();
	}

	public function test_a_constant_defined_as_null_is_resolved(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', null );" );

		$this->assertTrue( $config->has( 'EXAMPLE_VALUE' ) );
		$this->assertNull( $config->value( 'EXAMPLE_VALUE' ) );
		$this->assertNull( $config->skip_reason( 'EXAMPLE_VALUE' ) );
	}
}
