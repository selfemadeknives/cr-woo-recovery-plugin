<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checks GitHub for a newer version and wires into WordPress's
 * built-in plugin update system so updates appear in the normal
 * Plugins → Updates screen.
 *
 * Update flow:
 *   1. Bump CR_VERSION in cart-recovery.php
 *   2. Update version.json in the repo root to match
 *   3. Run the zip script to create cr-woo-recovery.zip
 *   4. Create a GitHub Release tagged vX.X.X and attach the zip
 *   5. Push everything — WordPress will detect the update automatically
 */
class CR_Updater {

    const SLUG       = 'cr-woo-recovery';
    const PLUGIN_FILE = 'cr-woo-recovery/cart-recovery.php';
    const VERSION_URL = 'https://raw.githubusercontent.com/selfemadeknives/cr-woo-recovery-plugin/main/version.json';

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_update' ] );
        add_filter( 'plugins_api',                           [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_action( 'upgrader_process_complete',             [ __CLASS__, 'purge_cache' ], 10, 2 );
    }

    // ------------------------------------------------------------------ update check

    public static function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $remote = self::get_remote_data();
        if ( ! $remote ) return $transient;

        if ( version_compare( CR_VERSION, $remote->version, '<' ) ) {
            $transient->response[ self::PLUGIN_FILE ] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => self::PLUGIN_FILE,
                'new_version' => $remote->version,
                'url'         => $remote->details_url ?? '',
                'package'     => $remote->download_url,
            ];
        } else {
            // Explicitly mark as no update so WP doesn't leave stale notices
            $transient->no_update[ self::PLUGIN_FILE ] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => self::PLUGIN_FILE,
                'new_version' => $remote->version,
                'url'         => $remote->details_url ?? '',
                'package'     => '',
            ];
        }

        return $transient;
    }

    // ------------------------------------------------------------------ plugin info (shown in the update modal)

    public static function plugin_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== self::SLUG ) return $result;

        $remote = self::get_remote_data();
        if ( ! $remote ) return $result;

        return (object) [
            'name'          => 'Cart Recovery for WooCommerce',
            'slug'          => self::SLUG,
            'version'       => $remote->version,
            'author'        => 'Selfe Made Knives',
            'download_link' => $remote->download_url,
            'trunk'         => $remote->download_url,
            'requires'      => '6.0',
            'tested'        => '6.7',
            'last_updated'  => $remote->updated ?? '',
            'sections'      => [
                'description' => 'Tracks abandoned WooCommerce carts and helps you send personal recovery emails.',
                'changelog'   => $remote->changelog ?? 'See GitHub for release notes.',
            ],
        ];
    }

    // ------------------------------------------------------------------ cache management

    public static function purge_cache( $upgrader, array $options ): void {
        if (
            $options['action'] === 'update' &&
            $options['type']   === 'plugin' &&
            isset( $options['plugins'] ) &&
            in_array( self::PLUGIN_FILE, (array) $options['plugins'], true )
        ) {
            delete_transient( 'cr_remote_version' );
        }
    }

    // ------------------------------------------------------------------ fetch remote version.json

    private static function get_remote_data(): ?object {
        $cached = get_transient( 'cr_remote_version' );
        if ( false !== $cached ) return $cached ?: null;

        $response = wp_remote_get( self::VERSION_URL, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache failure briefly so we don't hammer GitHub on every page load
            set_transient( 'cr_remote_version', false, 30 * MINUTE_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $data || empty( $data->version ) || empty( $data->download_url ) ) {
            set_transient( 'cr_remote_version', false, 30 * MINUTE_IN_SECONDS );
            return null;
        }

        set_transient( 'cr_remote_version', $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }
}
