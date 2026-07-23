<?php
/**
 * Plugin bootstrap.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the components together.
 */
final class Coywolf_Search_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Coywolf_Search_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Indexer.
	 *
	 * @var Coywolf_Search_Indexer
	 */
	private $indexer;

	/**
	 * Rebuilder.
	 *
	 * @var Coywolf_Search_Rebuilder
	 */
	private $rebuilder;

	/**
	 * Query integration.
	 *
	 * @var Coywolf_Search_Query_Integration
	 */
	private $integration;

	/**
	 * REST controller.
	 *
	 * @var Coywolf_Search_REST_Controller
	 */
	private $rest;

	/**
	 * Typeahead payload.
	 *
	 * @var Coywolf_Search_Typeahead
	 */
	private $typeahead;

	/**
	 * Front-end assets.
	 *
	 * @var Coywolf_Search_Assets
	 */
	private $assets;

	/**
	 * Admin screen.
	 *
	 * @var Coywolf_Search_Admin
	 */
	private $admin;

	/**
	 * Boot once.
	 *
	 * @return Coywolf_Search_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function init() {
		$this->indexer     = new Coywolf_Search_Indexer();
		$this->rebuilder   = new Coywolf_Search_Rebuilder();
		$this->integration = new Coywolf_Search_Query_Integration();
		$this->rest        = new Coywolf_Search_REST_Controller();
		$this->typeahead   = new Coywolf_Search_Typeahead();
		$this->assets      = new Coywolf_Search_Assets();
		$this->admin       = new Coywolf_Search_Admin();

		$this->indexer->init();
		$this->rebuilder->init();
		$this->integration->init();
		$this->rest->init();
		$this->typeahead->init();

		if ( ! is_admin() ) {
			$this->assets->init();
		}

		if ( is_admin() ) {
			$this->admin->init();
			add_action( 'admin_init', array( 'Coywolf_Search_Schema', 'maybe_upgrade' ) );
		}

		// The Settings API writes the option directly, so the per-request memo
		// has to be dropped after a save or the same request would render the
		// old values back into the form. The same hook decides whether the
		// change means the index has to be rebuilt.
		add_action( 'update_option_' . Coywolf_Search_Settings::OPTION, array( 'Coywolf_Search_Settings', 'on_updated' ), 10, 2 );
		add_action( 'add_option_' . Coywolf_Search_Settings::OPTION, array( 'Coywolf_Search_Settings', 'flush' ) );
	}

	/**
	 * The indexer instance.
	 *
	 * @return Coywolf_Search_Indexer
	 */
	public function indexer() {
		return $this->indexer;
	}
}
