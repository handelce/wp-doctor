<?php
/**
 * Administrator accounts and the signs of one created by an attacker.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * Lists the accounts that hold the administrator role and judges each against
 * published indicators (Rogue_Admin_Indicators), its email domain, and how it
 * sits among the other administrators. Every finding beyond the plain list is
 * worded "likely": a match is a reason to verify an account, not proof.
 *
 * @since 0.1.0
 */
final class Admin_Users_Check implements Check {

	private const CLUSTER_WINDOW_SECONDS = 86400;
	private const LIMIT                  = 200;
	private const SOFT_SIGNALS_NEEDED    = 2;
	private const INDICATOR_BASIS        = 'published incident reports listed in docs/DATA-SOURCES.md; a match means likely, not proven';

	/**
	 * Indicators to judge against. Null loads the bundled set.
	 *
	 * @since 0.1.0
	 *
	 * @var Rogue_Admin_Indicators|null
	 */
	private $indicators;

	/**
	 * Takes the indicator set to judge against.
	 *
	 * @since 0.1.0
	 *
	 * @param Rogue_Admin_Indicators|null $indicators Indicator set, or null for the files in data/.
	 */
	public function __construct( ?Rogue_Admin_Indicators $indicators = null ) {
		$this->indicators = $indicators;
	}

	/**
	 * Id shared by the administrator list and every indicator finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.users.admins';
	}

	/**
	 * Security, with the other compromise checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Lists and judges administrators.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the users, usermeta and posts tables and the cluster threshold.
	 * @return Result
	 * @throws \UnexpectedValueException When a returned row is malformed.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$prefix = $context->config()->table_prefix();
		$rows   = $context->database()->query(
			'SELECT u.ID AS user_id, u.user_login AS login, u.user_email AS email, u.user_registered AS registered,'
			. ' u.display_name AS display_name, u.user_url AS url FROM ' . $context->table( 'users' ) . ' AS u'
			. ' INNER JOIN ' . $context->table( 'usermeta' ) . ' AS m ON m.user_id = u.ID'
			. " WHERE m.meta_key = '{$prefix}capabilities' AND m.meta_value LIKE '%\"administrator\"%'"
			. ' ORDER BY u.user_registered DESC, u.ID DESC LIMIT ' . ( self::LIMIT + 1 )
		);
		if ( array() === $rows ) {
			return Result::skipped( esc_cli( "No administrator accounts found in {$context->table( 'users' )}; the table prefix or database may be wrong" ) );
		}
		// The newest accounts are the likeliest to be rogue, so a cut drops the oldest; the rules below need registration order.
		$truncated = count( $rows ) > self::LIMIT;
		$rows      = array_reverse( array_slice( $rows, 0, self::LIMIT ) );

		$admins = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['user_id'], $row['login'], $row['registered'] ) || ! array_key_exists( 'email', $row )
				|| ! array_key_exists( 'display_name', $row ) || ! array_key_exists( 'url', $row )
				|| 1 !== preg_match( '/^\d+$/', $row['user_id'] ) ) {
				throw new \UnexpectedValueException( 'The administrator query returned an unexpected row' );
			}
			$admins[] = array(
				'id'           => (int) $row['user_id'],
				'login'        => $row['login'],
				'email'        => (string) $row['email'],
				'display_name' => (string) $row['display_name'],
				'url'          => (string) $row['url'],
				'registered'   => $row['registered'],
				'time'         => $this->time_of( $row['registered'] ),
				'posts'        => 0,
			);
		}
		$admins = $this->with_post_counts( $context, $admins );

		$findings = array(
			new Finding(
				$this->id(),
				Severity::info(),
				'Administrator accounts',
				array_merge( array( 'rule' => 'administrator_list' ), $this->evidence( $admins, $truncated ) ),
				'Check that you recognise every account and that each still needs administrator rights. Remove or demote the ones that '
				. 'do not, and give each real person their own account.'
			),
		);

		$indicators   = $this->indicators ?? Rogue_Admin_Indicators::bundled();
		$judged       = array_values(
			array_filter(
				$admins,
				static function ( array $admin ) use ( $indicators ): bool {
					return ! $indicators->is_allowlisted( (string) $admin['login'] );
				}
			)
		);
		$cluster_size = $context->thresholds()->critical( $this->id() );
		$cluster      = $this->clustered( $judged, $cluster_size );
		if ( array() !== $cluster ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::critical(),
				'Several administrator accounts were likely created within a day of each other',
				array_merge(
					array(
						'rule'            => 'registration_cluster',
						'cluster_size'    => count( $cluster ),
						'cluster_rule'    => $cluster_size . ' or more within ' . self::CLUSTER_WINDOW_SECONDS . ' seconds',
						'threshold_basis' => $context->thresholds()->basis( $this->id() ),
						'possible_cause'  => 'an import or migration (the WordPress Importer, wp user import-csv, a site move) gives accounts the same registration time',
					),
					$this->evidence( $cluster, false )
				),
				'First check whether these accounts came from an import or a site move on that date. If not, and you did not create '
				. 'them, treat the site as compromised: remove them, change every administrator password and the keys and salts, and '
				. 'look for how the accounts were created (access logs, plugin and theme changes).'
			);
		}

		return Result::findings( ...array_merge( $findings, $this->indicator_findings( $admins ) ) );
	}

	/**
	 * Adds each administrator's authored post count.
	 *
	 * @since 0.1.0
	 *
	 * @param Context                          $context Gives the posts table.
	 * @param array<int, array<string, mixed>> $admins  Accounts with integer ids.
	 * @return array<int, array<string, mixed>>
	 * @throws \UnexpectedValueException When a returned row is malformed.
	 */
	private function with_post_counts( Context $context, array $admins ): array {
		$ids   = array_map(
			static function ( array $admin ): int {
				return (int) $admin['id'];
			},
			$admins
		);
		$rows  = $context->database()->query(
			'SELECT post_author AS user_id, COUNT(*) AS posts FROM ' . $context->table( 'posts' )
			. ' WHERE post_author IN (' . implode( ',', $ids ) . ') GROUP BY post_author'
		);
		$by_id = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['user_id'], $row['posts'] ) || 1 !== preg_match( '/^\d+$/', $row['posts'] ) ) {
				throw new \UnexpectedValueException( 'The post count query returned an unexpected row' );
			}
			$by_id[ (int) $row['user_id'] ] = (int) $row['posts'];
		}
		foreach ( $admins as &$admin ) {
			$admin['posts'] = $by_id[ $admin['id'] ] ?? 0;
		}
		unset( $admin );

		return $admins;
	}

	/**
	 * Findings from the published indicators, the email domains and the odd one out.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $admins Accounts with post counts.
	 * @return Finding[]
	 */
	private function indicator_findings( array $admins ): array {
		$indicators = $this->indicators ?? Rogue_Admin_Indicators::bundled();
		$newest     = $this->newest_time( $admins );
		$strong     = array();
		$soft       = array();
		$email      = array();

		foreach ( $admins as $admin ) {
			if ( $indicators->is_allowlisted( (string) $admin['login'] ) ) {
				continue;
			}
			$entry = $indicators->name_indicator( (string) $admin['login'] );
			if ( null !== $entry ) {
				$matched = 'exact' === $entry['match'] ? 'login equals ' . $entry['login'] : 'login starts with ' . $entry['login'];
				if ( Rogue_Admin_Indicators::STRONG === $entry['signal'] ) {
					$strong[] = $this->hit( $admin, $matched, $entry );
				} elseif ( isset( $entry['email'] ) && strtolower( (string) $admin['email'] ) === $entry['email'] ) {
					$strong[] = $this->hit( $admin, $matched . ' and the email matches the report', $entry );
				} else {
					$signals = $this->supporting_signals( $admin, $newest );
					if ( count( $signals ) >= self::SOFT_SIGNALS_NEEDED ) {
						$soft[] = $this->hit( $admin, $matched . '; ' . implode( ', ', $signals ), $entry );
					}
				}
			}

			$reason = $this->email_problem( $indicators, (string) $admin['email'] );
			if ( null !== $reason ) {
				$email[] = $this->hit( $admin, $reason, null );
			}
		}

		$findings = array();
		if ( array() !== $strong ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::critical(),
				'Administrator accounts likely created by an attacker: the login matches a published indicator',
				$this->hit_evidence( 'name_indicator', $strong ),
				'If you did not create these accounts, treat the site as compromised: remove them, change every administrator password '
				. 'and the keys and salts, and look for how they were created. Each account lists the report its name comes from.'
			);
		}
		if ( array() !== $email ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::warning(),
				'Administrator accounts with an email address that is likely not a real person\'s',
				$this->hit_evidence( 'email_address', $email ),
				'WordPress\'s own forms require a valid address, so an empty or placeholder one means the account was likely created by '
				. 'code or by writing to the database, and a disposable inbox is unusual for an administrator. Confirm each account '
				. 'with the site owner and remove the ones nobody recognises.'
			);
		}
		if ( array() !== $soft ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::warning(),
				'Administrator accounts that likely match a published report and look created by code',
				$this->hit_evidence( 'soft_indicator', $soft ),
				'The login appears in a public incident report and the account shows signs of programmatic creation. Confirm it with '
				. 'the site owner; if nobody created it, treat the site as compromised and follow the report linked in the evidence.'
			);
		}
		$odd = $this->odd_one_out( $indicators, $admins, $newest );
		if ( null !== $odd ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::info(),
				'One administrator is likely the odd one out',
				$this->hit_evidence( 'odd_one_out', array( $odd ) ),
				'This administrator is the only one with a different email domain, was registered most recently, and has authored '
				. 'nothing. That is also what a newly added contractor looks like, so confirm it with the site owner.'
			);
		}

		return $findings;
	}

	/**
	 * Why an email address is likely not a real person's, or null when it looks ordinary.
	 *
	 * @since 0.1.0
	 *
	 * @param Rogue_Admin_Indicators $indicators Placeholder and disposable-mail domain lists.
	 * @param string                 $email      Address as stored.
	 * @return string|null
	 */
	private function email_problem( Rogue_Admin_Indicators $indicators, string $email ): ?string {
		if ( '' === trim( $email ) ) {
			return 'no email address';
		}
		if ( 1 !== preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ) {
			return 'not a valid email address';
		}
		$domain = rtrim( strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) ), '.' );
		if ( $indicators->is_placeholder_domain( $domain ) ) {
			return 'placeholder email domain';
		}
		if ( $indicators->is_disposable_domain( $domain ) ) {
			return 'disposable email domain';
		}

		return null;
	}

	/**
	 * Signs that an account was added by an attack rather than by a person. The
	 * display name and website are not used: an account added in Users > Add
	 * New has the login as display name and no website by default.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $admin  Account with its post count.
	 * @param int|null             $newest Latest registration time among the administrators.
	 * @return string[]
	 */
	private function supporting_signals( array $admin, ?int $newest ): array {
		$signals = array();
		if ( null !== $newest && $admin['time'] === $newest ) {
			$signals[] = 'newest registration';
		}
		if ( 0 === $admin['posts'] ) {
			$signals[] = 'no posts';
		}

		return $signals;
	}

	/**
	 * The single administrator whose email domain differs from every other's,
	 * when the others share one domain and it is the newest with no posts.
	 *
	 * @since 0.1.0
	 *
	 * @param Rogue_Admin_Indicators           $indicators Consulted for the allowlist only.
	 * @param array<int, array<string, mixed>> $admins     Every administrator, with post counts.
	 * @param int|null                         $newest     Latest registration time.
	 * @return array<string, string>|null The hit, or null when no single account stands out.
	 */
	private function odd_one_out( Rogue_Admin_Indicators $indicators, array $admins, ?int $newest ): ?array {
		if ( count( $admins ) < 3 || null === $newest ) {
			return null;
		}
		$domains = array();
		foreach ( $admins as $index => $admin ) {
			$email = (string) $admin['email'];
			if ( false === strpos( $email, '@' ) ) {
				return null;
			}
			$domains[ $index ] = rtrim( strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) ), '.' );
		}
		$counts = array_count_values( $domains );
		if ( 2 !== count( $counts ) || ! in_array( 1, $counts, true ) ) {
			return null;
		}
		$index = null;
		foreach ( $domains as $candidate => $domain ) {
			if ( 1 === $counts[ $domain ] ) {
				$index = $candidate;
				break;
			}
		}
		if ( null === $index ) {
			return null;
		}
		$admin = $admins[ $index ];
		if ( $admin['time'] !== $newest || 0 !== $admin['posts'] || $indicators->is_allowlisted( (string) $admin['login'] ) ) {
			return null;
		}

		return $this->hit( $admin, 'only administrator on ' . $domains[ $index ] . ', newest registration, no posts', null );
	}

	/**
	 * One account and why it was flagged.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>       $admin  Account to describe.
	 * @param string                     $reason What matched.
	 * @param array<string, string>|null $entry  Indicator entry, when a name matched.
	 * @return array<string, string>
	 */
	private function hit( array $admin, string $reason, ?array $entry ): array {
		$hit = array(
			'login'      => (string) $admin['login'],
			'email'      => (string) $admin['email'],
			'registered' => null === $admin['time'] ? 'unknown' : (string) $admin['registered'],
			'reason'     => $reason,
		);
		if ( null !== $entry ) {
			$hit['campaign'] = $entry['campaign'];
			$hit['source']   = $entry['source'];
		}

		return $hit;
	}

	/**
	 * Numbered hits with the rule that produced them.
	 *
	 * @since 0.1.0
	 *
	 * @param string                            $rule Rule key written to the evidence, such as name_indicator.
	 * @param array<int, array<string, string>> $hits Hits built by hit().
	 * @return array<string, string|int>
	 */
	private function hit_evidence( string $rule, array $hits ): array {
		$evidence = array(
			'rule'            => $rule,
			'matches'         => count( $hits ),
			'indicator_basis' => self::INDICATOR_BASIS,
		);
		foreach ( array_slice( $hits, 0, Evidence::MAX_LISTED ) as $index => $hit ) {
			$rank = $index + 1;
			foreach ( $hit as $field => $value ) {
				$evidence[ 'login' === $field ? "admin_$rank" : "admin_{$rank}_$field" ] = $value;
			}
		}

		return $evidence;
	}

	/**
	 * Latest readable registration time, or null when no date could be read.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $admins Accounts with parsed registration times.
	 * @return int|null
	 */
	private function newest_time( array $admins ): ?int {
		$newest = null;
		foreach ( $admins as $admin ) {
			if ( null !== $admin['time'] && ( null === $newest || $admin['time'] > $newest ) ) {
				$newest = $admin['time'];
			}
		}

		return $newest;
	}

	/**
	 * Numbered account details.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $admins    Accounts to list, in registration order.
	 * @param bool                             $truncated Whether the query hit its row limit.
	 * @return array<string, string|int|bool>
	 */
	private function evidence( array $admins, bool $truncated ): array {
		$evidence = array(
			'administrators' => count( $admins ),
			'list_truncated' => $truncated,
		);
		foreach ( array_slice( $admins, 0, Evidence::MAX_LISTED ) as $index => $admin ) {
			$rank                                   = $index + 1;
			$evidence[ "admin_$rank" ]              = (string) $admin['login'];
			$evidence[ "admin_{$rank}_registered" ] = null === $admin['time'] ? 'unknown' : (string) $admin['registered'];
			$evidence[ "admin_{$rank}_email" ]      = (string) $admin['email'];
			$evidence[ "admin_{$rank}_posts" ]      = (int) $admin['posts'];
		}

		return $evidence;
	}

	/**
	 * Accounts that belong to a group registered within the window.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $admins Accounts in registration order.
	 * @param int                              $size   Accounts within the window that make a cluster.
	 * @return array<int, array<string, mixed>>
	 */
	private function clustered( array $admins, int $size ): array {
		$dated  = array_values(
			array_filter(
				$admins,
				static function ( array $admin ): bool {
					return null !== $admin['time'];
				}
			)
		);
		$member = array();
		$count  = count( $dated );
		for ( $start = 0; $start < $count; $start++ ) {
			$end = $start;
			while ( $end + 1 < $count && $dated[ $end + 1 ]['time'] - $dated[ $start ]['time'] <= self::CLUSTER_WINDOW_SECONDS ) {
				++$end;
			}
			if ( $end - $start + 1 >= $size ) {
				for ( $i = $start; $i <= $end; $i++ ) {
					$member[ $i ] = true;
				}
			}
		}

		return array_values( array_intersect_key( $dated, $member ) );
	}

	/**
	 * Unix time of a UTC registration date.
	 *
	 * @since 0.1.0
	 *
	 * @param string $registered Value of user_registered.
	 * @return int|null Null when the date is not a valid date.
	 */
	private function time_of( string $registered ): ?int {
		$date   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $registered, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->getTimestamp();
	}
}
