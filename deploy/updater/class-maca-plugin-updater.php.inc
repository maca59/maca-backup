<?php
/**
 * WordPress plugin updates from api.maca.se (shared by maca plugins).
 *
 * @package Maca_Translate
 */

defined( 'ABSPATH' ) || exit;

if (! class_exists('Maca_Plugin_Updater', false)) {

/**
 * Generic updater — configure per plugin via constructor array.
 */
class Maca_Plugin_Updater {

	const CACHE_SECONDS      = 43200;
	const ERROR_CACHE_SECS   = 3600;

	/** @var array<string, mixed> */
	private $config;

	/** @var bool */
	private $hooks_registered = false;

	/**
	 * @param array<string, mixed> $config {
	 *     @type string          $slug                 Plugin folder slug.
	 *     @type string          $basename             plugin_basename(), e.g. maca-hold/maca-hold.php.
	 *     @type string|callable $version              Installed version or callable returning it.
	 *     @type string          $update_url           Manifest URL (update.json).
	 *     @type string          $name                 Display name.
	 *     @type string          $homepage             Plugin homepage.
	 *     @type string          $description          Short description for plugins_api.
	 *     @type string          $check_updates_filter Filter name; default maca_{slug}_check_updates.
	 *     @type string          $admin_screen         Screen id substring for admin notice.
	 *     @type string          $text_domain          Optional text domain for strings.
	 * }
	 */
	public function __construct( array $config ) {
		$slug = isset( $config['slug'] ) ? sanitize_key( (string) $config['slug'] ) : '';
		$filter_slug = preg_replace( '/^maca-/', '', $slug );

		$this->config = wp_parse_args(
			$config,
			array(
				'slug'                 => $slug,
				'basename'             => '',
				'version'              => '',
				'update_url'           => '',
				'name'                 => '',
				'homepage'             => 'https://maca.se',
				'description'          => '',
				'check_updates_filter' => '' !== $filter_slug ? 'maca_' . str_replace( '-', '_', $filter_slug ) . '_check_updates' : 'maca_plugin_check_updates',
				'admin_screen'         => $slug,
				'text_domain'          => $slug,
			)
		);

		if ( '' === $this->config['basename'] || '' === $this->config['update_url'] ) {
			return;
		}

		if ( ! $this->updates_enabled() ) {
			return;
		}

		$this->register_hooks();
	}

	/**
	 * @return bool
	 */
	private function updates_enabled() {
		/**
		 * Filter whether this plugin may check api.maca.se for updates.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( (string) $this->config['check_updates_filter'], true );
	}

	private function register_hooks() {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'in_plugin_update_message-' . $this->config['basename'], array( $this, 'update_message' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		}
	}

	/**
	 * @return string
	 */
	private function installed_version() {
		$version = $this->config['version'];

		if ( is_callable( $version ) ) {
			return (string) call_user_func( $version );
		}

		return (string) $version;
	}

	/**
	 * @return string
	 */
	private function cache_key() {
		return 'maca_upd_' . md5( (string) $this->config['slug'] );
	}

	/**
	 * @return string[]
	 */
	private function update_urls() {
		$urls = array( (string) $this->config['update_url'] );

		/**
		 * Filter update manifest URLs for this plugin.
		 *
		 * @param string[] $urls Manifest URLs.
		 * @param string   $slug Plugin slug.
		 */
		$urls = apply_filters( 'maca_plugin_update_urls', $urls, (string) $this->config['slug'] );

		return array_values(
			array_filter(
				array_map( 'esc_url_raw', array_map( 'strval', (array) $urls ) )
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_remote_info() {
		$cached = get_transient( $this->cache_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'error' === $cached ) {
			return null;
		}

		$remote = null;
		foreach ( $this->update_urls() as $url ) {
			$remote = $this->fetch_manifest( $url );
			if ( null !== $remote ) {
				break;
			}
		}

		if ( null === $remote ) {
			set_transient( $this->cache_key(), 'error', self::ERROR_CACHE_SECS );
			return null;
		}

		set_transient( $this->cache_key(), $remote, self::CACHE_SECONDS );
		return $remote;
	}

	/**
	 * @param string $url Manifest URL.
	 * @return array<string, mixed>|null
	 */
	private function fetch_manifest( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => $this->config['slug'] . '/' . $this->installed_version() . '; ' . get_bloginfo( 'url' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		return $this->normalize_manifest( $body );
	}

	/**
	 * @param array<string, mixed> $body Raw JSON.
	 * @return array<string, mixed>|null
	 */
	private function normalize_manifest( array $body ) {
		$version = isset( $body['version'] ) ? trim( (string) $body['version'] ) : '';
		if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			return null;
		}

		$download_url = isset( $body['download_url'] ) ? esc_url_raw( (string) $body['download_url'] ) : '';
		if ( ! $this->is_trusted_download_url( $download_url ) ) {
			return null;
		}

		$manifest = array(
			'version'      => $version,
			'download_url' => $download_url,
			'requires'     => isset( $body['requires'] ) ? (string) $body['requires'] : '6.0',
			'requires_php' => isset( $body['requires_php'] ) ? (string) $body['requires_php'] : '7.4',
			'tested'       => isset( $body['tested'] ) ? (string) $body['tested'] : '',
			'last_updated' => isset( $body['last_updated'] ) ? (string) $body['last_updated'] : '',
			'changelog'    => isset( $body['changelog'] ) ? (string) $body['changelog'] : '',
		);

		if ( ! empty( $body['sha256'] ) && is_string( $body['sha256'] ) ) {
			$manifest['sha256'] = strtolower( preg_replace( '/[^a-f0-9]/i', '', $body['sha256'] ) );
		}

		return $manifest;
	}

	/**
	 * @param string $url Download URL.
	 * @return bool
	 */
	private function is_trusted_download_url( $url ) {
		if ( '' === $url || 0 !== stripos( $url, 'https://' ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );

		return 'maca.se' === $host
			|| 'api.maca.se' === $host
			|| ( strlen( $host ) > 8 && '.maca.se' === substr( $host, -8 ) );
	}

	/**
	 * @param object|false $transient Update plugins transient.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$remote = $this->get_remote_info();
		if ( null === $remote ) {
			return $transient;
		}

		$installed = $this->installed_version();
		if ( '' === $installed || version_compare( $installed, $remote['version'], '>=' ) ) {
			return $transient;
		}

		if ( ! empty( $remote['requires'] ) && version_compare( get_bloginfo( 'version' ), $remote['requires'], '<' ) ) {
			return $transient;
		}

		if ( ! empty( $remote['requires_php'] ) && version_compare( PHP_VERSION, $remote['requires_php'], '<' ) ) {
			return $transient;
		}

		$item               = new stdClass();
		$item->slug         = (string) $this->config['slug'];
		$item->plugin       = (string) $this->config['basename'];
		$item->new_version  = $remote['version'];
		$item->url          = (string) $this->config['homepage'];
		$item->package      = $remote['download_url'];
		$item->requires     = $remote['requires'];
		$item->requires_php = $remote['requires_php'];
		$item->tested       = $remote['tested'];
		$item->id           = (string) $this->config['basename'];

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ (string) $this->config['basename'] ] = $item;

		return $transient;
	}

	/**
	 * @param false|object|array $result Plugin info.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! is_object( $args ) || empty( $args->slug ) || (string) $this->config['slug'] !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_info();
		if ( null === $remote ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = (string) $this->config['name'];
		$info->slug          = (string) $this->config['slug'];
		$info->version       = $remote['version'];
		$info->author        = '<a href="https://maca.se">maca Development</a>';
		$info->homepage      = (string) $this->config['homepage'];
		$info->requires      = $remote['requires'];
		$info->requires_php  = $remote['requires_php'];
		$info->tested        = $remote['tested'];
		$info->download_link = $remote['download_url'];
		$info->last_updated  = $remote['last_updated'];
		$info->sections      = array(
			'description' => (string) $this->config['description'],
			'changelog'   => '' !== $remote['changelog'] ? wp_kses_post( $remote['changelog'] ) : '',
		);

		return $info;
	}

	public function clear_cache() {
		delete_transient( $this->cache_key() );
	}

	/**
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade options.
	 */
	public function after_upgrade( $upgrader, $options ) {
		unset( $upgrader );

		if ( empty( $options['action'] ) || 'update' !== $options['action'] ) {
			return;
		}
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		if ( in_array( (string) $this->config['basename'], $options['plugins'], true ) ) {
			$this->clear_cache();
		}
	}

	/**
	 * @param array  $plugin_data Plugin row data.
	 * @param object $response    Update response.
	 */
	public function update_message( $plugin_data, $response ) {
		unset( $plugin_data );

		if ( empty( $response->new_version ) ) {
			return;
		}

		$remote = $this->get_remote_info();
		if ( null === $remote || empty( $remote['changelog'] ) ) {
			return;
		}

		$changelog = wp_strip_all_tags( $remote['changelog'] );
		if ( '' === $changelog ) {
			return;
		}

		if ( strlen( $changelog ) > 220 ) {
			$changelog = substr( $changelog, 0, 217 ) . '…';
		}

		echo ' <em>' . esc_html( $changelog ) . '</em>';
	}

	public function admin_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$needle = (string) $this->config['admin_screen'];
		if ( ! $screen || '' === $needle || false === strpos( (string) $screen->id, $needle ) ) {
			return;
		}

		$remote = $this->get_remote_info();
		$installed = $this->installed_version();
		if ( null === $remote || '' === $installed || version_compare( $installed, $remote['version'], '>=' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: 1: plugin name, 2: new version, 3: current version, 4: updates link, 5: plugins link */
			esc_html__( '%1$s: version %2$s finns tillgänglig (du kör %3$s). %4$s eller %5$s.', 'maca-backup-pro' ),
			esc_html( (string) $this->config['name'] ),
			esc_html( $remote['version'] ),
			esc_html( $installed ),
			'<a href="' . esc_url( network_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Gå till uppdateringar', 'maca-backup-pro' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Uppdatera under Tillägg', 'maca-backup-pro' ) . '</a>'
		);
		echo '</p></div>';
	}
}

}
