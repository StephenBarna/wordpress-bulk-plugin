<?php
/**
 * Singleton plugin bootstrap. Wires up the admin UI on the right hooks.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			Admin\Admin_Menu::register();
			Admin\Settings_Page::register();
			Admin\New_Job_Screen::register();
			Admin\Inspect_Screen::register();
			Admin\Patch_Screen::register();
			Admin\Leak_Scanner_Screen::register();
			Admin\Coverage_Screen::register();
		}
	}

	private function __clone() {}

	public function __wakeup() {}
}
