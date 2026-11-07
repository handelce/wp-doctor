<?php
/**
 * Tests for Server_Config_Directives.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Server_Config_Directives;

final class Server_Config_Directives_Test extends TestCase {

	/**
	 * @dataProvider executing
	 * @param string $name File name.
	 * @param string $line Line that makes files run as PHP or prepends code.
	 */
	public function test_a_line_that_runs_code_is_returned( string $name, string $line ): void {
		$this->assertSame( array( $line ), Server_Config_Directives::executing_lines( $name, "# comment\n  $line  \n" ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function executing(): array {
		return array(
			'add type'          => array( '.htaccess', 'AddType application/x-httpd-php .jpg .png' ),
			'add handler'       => array( '.htaccess', 'AddHandler application/x-httpd-ea-php74 .gif' ),
			'set handler'       => array( '.htaccess', 'SetHandler "proxy:fcgi://127.0.0.1:9000"' ),
			'force type'        => array( '.htaccess', 'ForceType application/x-httpd-php' ),
			'engine on'         => array( '.htaccess', 'php_flag engine on' ),
			'prepend, apache'   => array( '.htaccess', 'php_value auto_prepend_file /tmp/example.php' ),
			'prepend, user ini' => array( '.user.ini', 'auto_prepend_file = "/tmp/example.jpg"' ),
			'append, php ini'   => array( 'php.ini', 'auto_append_file=/tmp/example.txt' ),
		);
	}

	/**
	 * @dataProvider harmless
	 * @param string $name    File name.
	 * @param string $content Content that does not run code.
	 */
	public function test_rules_that_stop_php_or_change_nothing_are_not_returned( string $name, string $content ): void {
		$this->assertSame( array(), Server_Config_Directives::executing_lines( $name, $content ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function harmless(): array {
		return array(
			'engine off'      => array( '.htaccess', 'php_flag engine off' ),
			'text plain'      => array( '.htaccess', 'AddType text/plain .php .phtml' ),
			'handler none'    => array( '.htaccess', 'SetHandler none' ),
			'remove handler'  => array( '.htaccess', 'RemoveHandler .php' ),
			'deny'            => array( '.htaccess', "<Files *.php>\nRequire all denied\n</Files>" ),
			'prepend none'    => array( '.user.ini', 'auto_prepend_file = none' ),
			'prepend empty'   => array( '.user.ini', 'auto_prepend_file =' ),
			'commented'       => array( '.user.ini', '; auto_prepend_file = /tmp/x.php' ),
			'ini in htaccess' => array( '.htaccess', 'auto_prepend_file = /tmp/x.php' ),
			'limits'          => array( '.user.ini', "upload_max_filesize = 64M\nmemory_limit = 256M" ),
		);
	}

	public function test_the_providers_are_not_empty(): void {
		$this->assertCount( 8, $this->executing() );
		$this->assertCount( 10, $this->harmless() );
	}

	public function test_at_most_five_lines_are_returned(): void {
		$content = str_repeat( "AddType application/x-httpd-php .jpg\n", 9 );

		$this->assertCount( Server_Config_Directives::MAX_LINES, Server_Config_Directives::executing_lines( '.htaccess', $content ) );
	}
}
