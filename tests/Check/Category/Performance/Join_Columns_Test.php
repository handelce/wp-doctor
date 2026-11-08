<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Join_Columns;

final class Join_Columns_Test extends TestCase {

	public function test_tables_after_from_and_join_are_read_with_their_aliases(): void {
		$joins = new Join_Columns( 'SELECT p.ID FROM `wp_posts` AS p LEFT JOIN wp_postmeta m ON m.post_id = p.ID INNER JOIN shop.wp_orders ON wp_orders.post = p.ID WHERE p.post_type = \'x\'' );

		$this->assertSame(
			array(
				'm'           => 'wp_postmeta',
				'wp_postmeta' => 'wp_postmeta',
				'wp_orders'   => 'wp_orders',
				'p'           => 'wp_posts',
				'wp_posts'    => 'wp_posts',
			),
			$joins->tables()
		);
		$this->assertSame( array( array( 'm', 'post_id', 'p', 'id' ), array( 'wp_orders', 'post', 'p', 'id' ) ), $joins->joins() );
	}

	public function test_a_comma_list_after_from_is_read(): void {
		$joins = new Join_Columns( 'SELECT 1 FROM wp_users u, wp_usermeta AS um WHERE u.ID = um.user_id ORDER BY u.ID' );

		$this->assertSame( array( array( 'u', 'id', 'um', 'user_id' ) ), $joins->joins() );
	}

	public function test_text_inside_string_literals_is_not_taken_for_a_join(): void {
		$joins = new Join_Columns( "SELECT 1 FROM wp_posts p JOIN wp_users u ON u.ID = p.post_author WHERE p.post_title = 'a.b = c.d' AND p.post_name = \"x.y = u.z\"" );

		$this->assertSame( array( array( 'u', 'id', 'p', 'post_author' ) ), $joins->joins() );
	}

	public function test_equalities_with_an_unknown_alias_or_on_one_table_are_left_out(): void {
		$joins = new Join_Columns( 'SELECT 1 FROM wp_posts p WHERE p.ID = p.post_parent AND x.a = p.ID' );

		$this->assertSame( array(), $joins->joins() );
	}

	public function test_a_derived_table_is_passed_over(): void {
		$joins = new Join_Columns( 'SELECT 1 FROM (SELECT ID FROM wp_posts) d JOIN wp_postmeta m ON m.post_id = d.ID' );

		$this->assertArrayNotHasKey( 'd', $joins->tables() );
		$this->assertSame( array(), $joins->joins() );
	}
}
