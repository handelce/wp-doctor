<?php
/**
 * Tests for Statement_Gate.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Db;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Db\Statement_Gate;

final class Statement_Gate_Test extends TestCase {

	/**
	 * @dataProvider allowed_statements
	 * @param string $sql Statement the gate must accept.
	 */
	public function test_a_read_statement_is_allowed( string $sql ): void {
		( new Statement_Gate() )->assert_allowed( $sql );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function allowed_statements(): array {
		return array(
			'select'                          => array( 'SELECT COUNT(*) FROM wp_options' ),
			'lower case select'               => array( 'select option_name from wp_options' ),
			'leading whitespace'              => array( "  \n\tSELECT 1" ),
			'multi-line select'               => array( "SELECT option_name,\n LENGTH(option_value) AS bytes\nFROM `wp_options`\nWHERE autoload = 'yes'\nORDER BY bytes DESC\nLIMIT 20" ),
			'union'                           => array( 'SELECT 1 UNION SELECT 2' ),
			'subquery'                        => array( 'SELECT * FROM (SELECT 1 AS a) AS t' ),
			'show tables'                     => array( "SHOW TABLES LIKE 'wp_%'" ),
			'show table status'               => array( 'SHOW TABLE STATUS' ),
			'show columns'                    => array( 'SHOW FULL COLUMNS FROM `wp_options`' ),
			'describe'                        => array( 'DESCRIBE wp_options' ),
			'desc'                            => array( 'DESC wp_options' ),
			'explain'                         => array( 'EXPLAIN SELECT * FROM wp_options' ),
			'explain format'                  => array( 'EXPLAIN FORMAT=JSON SELECT * FROM wp_options' ),
			'semicolon inside a string'       => array( "SELECT 'a;b'" ),
			'comment marker inside a string'  => array( "SELECT 'a -- b', \"c # d\", 'e /* f */'" ),
			'doubled quote'                   => array( "SELECT 'it''s'" ),
			'keyword inside a string'         => array( "SELECT 'INTO OUTFILE FOR UPDATE SLEEP(1)'" ),
			'keyword as a quoted identifier'  => array( 'SELECT `into` FROM `sleep`' ),
			'non-ascii text'                  => array( "SELECT 'caf\u{e9}'" ),
			'describe quoted table'           => array( 'DESCRIBE `wp_options`' ),
			'describe qualified table'        => array( 'DESC `example_db`.`wp_options`' ),
			'describe one column'             => array( 'DESCRIBE wp_options option_name' ),
			'describe column pattern'         => array( "DESCRIBE `wp_options` 'option%'" ),
			'describe of a select'            => array( 'DESCRIBE SELECT * FROM wp_options' ),
			'quoted identifier in a call'     => array( 'SELECT COUNT(`option_id`), MAX( `option_id` ) FROM `wp_options`' ),
			'quoted identifier before a list' => array( 'SELECT `option_name` FROM `wp_options` WHERE `option_id` IN (1, 2)' ),
		);
	}

	public function test_the_allowed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->allowed_statements() );
	}

	/**
	 * @dataProvider rejected_statements
	 * @param string $sql Statement the gate must refuse.
	 */
	public function test_anything_else_is_rejected( string $sql ): void {
		$this->expectException( Read_Only_Violation_Exception::class );

		( new Statement_Gate() )->assert_allowed( $sql );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function rejected_statements(): array {
		return array(
			'empty'                                => array( '' ),
			'whitespace only'                      => array( " \n\t " ),
			'insert'                               => array( "INSERT INTO wp_options (option_name) VALUES ('a')" ),
			'update'                               => array( "UPDATE wp_options SET option_value = ''" ),
			'delete'                               => array( 'DELETE FROM wp_options' ),
			'replace'                              => array( "REPLACE INTO wp_options VALUES ('a')" ),
			'drop'                                 => array( 'DROP TABLE wp_options' ),
			'alter'                                => array( 'ALTER TABLE wp_options ADD x INT' ),
			'create'                               => array( 'CREATE TABLE t (a INT)' ),
			'truncate'                             => array( 'TRUNCATE wp_options' ),
			'set'                                  => array( 'SET SESSION TRANSACTION READ WRITE' ),
			'set autocommit'                       => array( 'SET autocommit = 0' ),
			'grant'                                => array( "GRANT ALL ON *.* TO 'u'@'%'" ),
			'call'                                 => array( 'CALL cleanup()' ),
			'do'                                   => array( 'DO SLEEP(1)' ),
			'use'                                  => array( 'USE other_db' ),
			'lock tables'                          => array( 'LOCK TABLES wp_options WRITE' ),
			'unlock'                               => array( 'UNLOCK TABLES' ),
			'start transaction'                    => array( 'START TRANSACTION' ),
			'begin'                                => array( 'BEGIN' ),
			'commit'                               => array( 'COMMIT' ),
			'rollback'                             => array( 'ROLLBACK' ),
			'load data'                            => array( "LOAD DATA INFILE '/etc/passwd' INTO TABLE t" ),
			'handler'                              => array( 'HANDLER wp_options OPEN' ),
			'prepare'                              => array( "PREPARE s FROM 'DELETE FROM wp_options'" ),
			'execute'                              => array( 'EXECUTE s' ),
			'analyze table'                        => array( 'ANALYZE TABLE wp_options' ),
			'optimize table'                       => array( 'OPTIMIZE TABLE wp_options' ),
			'repair table'                         => array( 'REPAIR TABLE wp_options' ),
			'flush'                                => array( 'FLUSH TABLES' ),
			'kill'                                 => array( 'KILL 5' ),
			'with cte'                             => array( 'WITH a AS (SELECT 1) SELECT * FROM a' ),
			'parenthesised select'                 => array( '(SELECT 1)' ),
			'misspelt select'                      => array( 'SELEC 1' ),
			'word starting with select'            => array( 'SELECTED 1' ),
			'two statements'                       => array( 'SELECT 1; DROP TABLE wp_options' ),
			'trailing semicolon'                   => array( 'SELECT 1;' ),
			'two selects'                          => array( 'SELECT 1; SELECT 2' ),
			'semicolon after a string'             => array( "SELECT 'a'; DELETE FROM wp_options" ),
			'line comment'                         => array( 'SELECT 1 -- x' ),
			'hash comment'                         => array( 'SELECT 1 # x' ),
			'block comment'                        => array( 'SELECT 1 /* x */' ),
			'leading block comment'                => array( '/**/SELECT 1' ),
			'executable comment'                   => array( 'SELECT 1 /*!50000 ; DROP TABLE wp_options */' ),
			'unterminated single quote'            => array( "SELECT 'a" ),
			'unterminated double quote'            => array( 'SELECT "a' ),
			'unterminated backtick'                => array( 'SELECT `a' ),
			'mismatched quotes hiding a semicolon' => array( "SELECT \"a' ; DROP TABLE t -- '" ),
			'backslash'                            => array( "SELECT 'a\\'; DROP TABLE t; -- '" ),
			'backslash alone'                      => array( "SELECT 'a\\nb'" ),
			'nul byte'                             => array( "SELECT 1\0" ),
			'control character'                    => array( "SELECT 1\x07" ),
			'into outfile'                         => array( "SELECT 1 INTO OUTFILE '/tmp/x'" ),
			'into dumpfile'                        => array( "SELECT 1 INTO DUMPFILE '/tmp/x'" ),
			'into variable'                        => array( 'SELECT 1 INTO @a' ),
			'lower case into'                      => array( "select 1 into outfile '/tmp/x'" ),
			'for update'                           => array( 'SELECT * FROM wp_options FOR UPDATE' ),
			'for share'                            => array( 'SELECT * FROM wp_options FOR SHARE' ),
			'lock in share mode'                   => array( 'SELECT * FROM wp_options LOCK IN SHARE MODE' ),
			'assignment'                           => array( 'SELECT @a := 1' ),
			'sleep'                                => array( 'SELECT SLEEP(10)' ),
			'sleep with space'                     => array( 'SELECT sleep (10)' ),
			'benchmark'                            => array( "SELECT BENCHMARK(1000000, MD5('a'))" ),
			'get_lock'                             => array( "SELECT GET_LOCK('a', 10)" ),
			'release_lock'                         => array( "SELECT RELEASE_LOCK('a')" ),
			'load_file'                            => array( "SELECT LOAD_FILE('/etc/passwd')" ),
			'explain analyze'                      => array( 'EXPLAIN ANALYZE SELECT 1' ),
			'explain delete'                       => array( 'EXPLAIN DELETE FROM wp_options' ),
			'explain update'                       => array( 'EXPLAIN UPDATE wp_options SET a = 1' ),
			'explain with into'                    => array( "EXPLAIN SELECT 1 INTO OUTFILE '/tmp/x'" ),
			'explain analyze delete'               => array( 'EXPLAIN ANALYZE DELETE FROM wp_options' ),
			'desc of a delete'                     => array( 'DESC DELETE FROM wp_options' ),
			'describe of a delete'                 => array( 'DESCRIBE DELETE FROM wp_options' ),
			'describe analyze of a delete'         => array( 'DESCRIBE ANALYZE DELETE o FROM wp_options o JOIN wp_users u ON 1=1' ),
			'describe format of an update'         => array( 'DESCRIBE FORMAT=JSON UPDATE wp_options SET a = 1' ),
			'describe of an insert'                => array( "DESC INSERT INTO wp_options (option_name) VALUES ('a')" ),
			'describe with extra words'            => array( 'DESCRIBE wp_options option_name option_value' ),
			'backtick-quoted sleep'                => array( 'SELECT `sleep`(2)' ),
			'backtick-quoted get_lock'             => array( "SELECT `get_lock`('x', 0)" ),
			'double-quoted sleep'                  => array( 'SELECT "sleep"(0)' ),
			'quoted name, space, parenthesis'      => array( "SELECT `sleep`\n (2)" ),
			'qualified quoted function'            => array( 'SELECT `example_db`.`cleanup`()' ),
			'quoted call in a where clause'        => array( 'SELECT 1 FROM wp_options WHERE `benchmark`(100, 1)' ),
			'master_pos_wait'                      => array( "SELECT MASTER_POS_WAIT('log.000001', 4, 100)" ),
			'source_pos_wait'                      => array( "SELECT SOURCE_POS_WAIT('log.000001', 4, 100)" ),
			'master_gtid_wait'                     => array( "SELECT MASTER_GTID_WAIT('0-1-1', 100)" ),
			'wait_for_executed_gtid_set'           => array( "SELECT WAIT_FOR_EXECUTED_GTID_SET('x', 100)" ),
			'wait_until_sql_thread_after_gtids'    => array( "SELECT WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS('x', 100)" ),
			'nextval'                              => array( 'SELECT NEXTVAL(s)' ),
			'setval'                               => array( 'SELECT SETVAL(s, 100)' ),
			'lastval'                              => array( 'SELECT LASTVAL(s)' ),
			'next value for'                       => array( 'SELECT NEXT VALUE FOR s' ),
			'previous value for'                   => array( "SELECT PREVIOUS\nVALUE FOR s" ),
			'release_all_locks'                    => array( 'SELECT RELEASE_ALL_LOCKS()' ),
			'locking service'                      => array( "SELECT service_get_write_locks('ns', 'l', 10)" ),
			'version tokens'                       => array( "SELECT version_tokens_set('a=1')" ),
			'group replication'                    => array( "SELECT group_replication_set_as_primary('x')" ),
			'connection failover'                  => array( "SELECT asynchronous_connection_failover_add_source('c', 'h', 3306, '', 50)" ),
			'keyring'                              => array( "SELECT keyring_key_remove('k', 'u')" ),
			'audit log'                            => array( "SELECT audit_log_filter_remove_filter('f')" ),
		);
	}

	public function test_the_rejected_provider_is_not_empty(): void {
		$this->assertGreaterThan( 50, count( $this->rejected_statements() ) );
	}

	public function test_a_rejection_names_the_reason_without_echoing_a_long_statement(): void {
		$long = 'INSERT INTO t VALUES (' . str_repeat( '1,', 500 ) . '1)';

		try {
			( new Statement_Gate() )->assert_allowed( $long );
			$this->fail( 'The statement was allowed' );
		} catch ( Read_Only_Violation_Exception $e ) {
			$this->assertStringContainsString( 'INSERT', $e->getMessage() );
			$this->assertLessThan( 300, strlen( $e->getMessage() ) );
		}
	}

	public function test_a_rejection_does_not_echo_control_characters(): void {
		try {
			( new Statement_Gate() )->assert_allowed( "DROP\x1b[2J TABLE t" );
			$this->fail( 'The statement was allowed' );
		} catch ( Read_Only_Violation_Exception $e ) {
			$this->assertStringNotContainsString( "\x1b", $e->getMessage() );
		}
	}
}
