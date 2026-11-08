<?php
/**
 * The checks that ship with wp-doctor.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use WP_Doctor\Check\Category\Config\Config_Permissions_Check;
use WP_Doctor\Check\Category\Config\Db_Version_Check;
use WP_Doctor\Check\Category\Config\Debug_Enabled_Check;
use WP_Doctor\Check\Category\Config\Default_Salts_Check;
use WP_Doctor\Check\Category\Config\Exposed_Files_Check;
use WP_Doctor\Check\Category\Config\File_Edit_Enabled_Check;
use WP_Doctor\Check\Category\Config\Multisite_Scope_Check;
use WP_Doctor\Check\Category\Config\Php_Settings_Check;
use WP_Doctor\Check\Category\Config\Php_Version_Check;
use WP_Doctor\Check\Category\Config\Writable_Files_Check;
use WP_Doctor\Check\Category\Config\Xmlrpc_Enabled_Check;
use WP_Doctor\Check\Category\Disk\Cache_Size_Check;
use WP_Doctor\Check\Category\Disk\Debug_Log_Size_Check;
use WP_Doctor\Check\Category\Disk\Error_Log_Size_Check;
use WP_Doctor\Check\Category\Disk\Leftover_Files_Check;
use WP_Doctor\Check\Category\Disk\Uploads_Size_Check;
use WP_Doctor\Check\Category\Performance\Autoload_Orphaned_Check;
use WP_Doctor\Check\Category\Performance\Autoload_Size_Check;
use WP_Doctor\Check\Category\Performance\Autoload_Top_Check;
use WP_Doctor\Check\Category\Performance\Cron_Disabled_Check;
use WP_Doctor\Check\Category\Performance\Cron_Duplicates_Check;
use WP_Doctor\Check\Category\Performance\Cron_Overdue_Check;
use WP_Doctor\Check\Category\Performance\Object_Cache_Missing_Check;
use WP_Doctor\Check\Category\Performance\Revisions_Count_Check;
use WP_Doctor\Check\Category\Performance\Tables_Bloat_Check;
use WP_Doctor\Check\Category\Performance\Tables_Engine_Check;
use WP_Doctor\Check\Category\Performance\Tables_Largest_Check;
use WP_Doctor\Check\Category\Performance\Transients_Expired_Check;
use WP_Doctor\Check\Category\Security\Admin_Users_Check;
use WP_Doctor\Check\Category\Security\Core_Checksums_Check;
use WP_Doctor\Check\Category\Security\Cron_Persistence_Check;
use WP_Doctor\Check\Category\Security\Inactive_Plugins_Check;
use WP_Doctor\Check\Category\Security\Injected_Content_Check;
use WP_Doctor\Check\Category\Security\Obfuscated_Code_Check;
use WP_Doctor\Check\Category\Security\Outdated_Updates_Check;
use WP_Doctor\Check\Category\Security\Plugin_Checksums_Check;
use WP_Doctor\Check\Category\Security\Recent_Changes_Check;
use WP_Doctor\Check\Category\Security\Remote_Code_Check;
use WP_Doctor\Check\Category\Security\Unknown_Plugins_Check;
use WP_Doctor\Check\Category\Security\Unknown_Themes_Check;
use WP_Doctor\Check\Category\Security\Uploads_Disguised_Check;
use WP_Doctor\Check\Category\Security\Uploads_Php_Check;

use function WP_Doctor\esc_cli;

/**
 * An explicit, ordered list of checks. Checks are listed in code rather than
 * discovered on disk, so nothing is loaded that was not written here.
 *
 * @since 0.1.0
 */
final class Check_Registry {

	/**
	 * Checks keyed by id, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Check>
	 */
	private $checks = array();

	/**
	 * Registers the checks and sorts them by id.
	 *
	 * @since 0.1.0
	 *
	 * @param Check ...$checks Checks to register.
	 * @throws \InvalidArgumentException When two checks share an id.
	 */
	public function __construct( Check ...$checks ) {
		foreach ( $checks as $check ) {
			if ( isset( $this->checks[ $check->id() ] ) ) {
				throw new \InvalidArgumentException( esc_cli( 'Duplicate check id: ' . $check->id() ) );
			}
			$this->checks[ $check->id() ] = $check;
		}
		ksort( $this->checks );
	}

	/**
	 * Every check that ships with wp-doctor.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function built_in(): self {
		return new self(
			new Admin_Users_Check(),
			new Autoload_Orphaned_Check(),
			new Autoload_Size_Check(),
			new Autoload_Top_Check(),
			new Cache_Size_Check(),
			new Config_Permissions_Check(),
			new Core_Checksums_Check(),
			new Cron_Disabled_Check(),
			new Cron_Duplicates_Check(),
			new Cron_Persistence_Check(),
			new Cron_Overdue_Check(),
			new Db_Version_Check(),
			new Debug_Enabled_Check(),
			new Debug_Log_Size_Check(),
			new Default_Salts_Check(),
			new Error_Log_Size_Check(),
			new Exposed_Files_Check(),
			new File_Edit_Enabled_Check(),
			new Inactive_Plugins_Check(),
			new Injected_Content_Check(),
			new Leftover_Files_Check(),
			new Multisite_Scope_Check(),
			new Obfuscated_Code_Check(),
			new Object_Cache_Missing_Check(),
			new Outdated_Updates_Check(),
			new Php_Settings_Check(),
			new Php_Version_Check(),
			new Plugin_Checksums_Check(),
			new Recent_Changes_Check(),
			new Remote_Code_Check(),
			new Revisions_Count_Check(),
			new Tables_Bloat_Check(),
			new Tables_Engine_Check(),
			new Tables_Largest_Check(),
			new Transients_Expired_Check(),
			new Unknown_Plugins_Check(),
			new Unknown_Themes_Check(),
			new Uploads_Disguised_Check(),
			new Uploads_Php_Check(),
			new Uploads_Size_Check(),
			new Writable_Files_Check(),
			new Xmlrpc_Enabled_Check()
		);
	}

	/**
	 * Ids of every registered check, in run order.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_map(
			static function ( Check $check ): string {
				return $check->id();
			},
			$this->all()
		);
	}

	/**
	 * Every check, ordered by id.
	 *
	 * @since 0.1.0
	 *
	 * @return Check[]
	 */
	public function all(): array {
		return array_values( $this->checks );
	}
}
