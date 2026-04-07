<?php

/**
 * Freemius Integration
 * 
 * This file initializes Freemius SDK and defines plan/feature helpers.
 * 
 * To integrate Freemius:
 * 1. Create account at https://freemius.com
 * 2. Add your plugin
 * 3. Download SDK and place in /freemius folder
 * 4. Replace the code below with the generated snippet
 * 
 * @package AIChat
 * @since 2.2.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Initialize Freemius
 * 
 * IMPORTANT: Replace this entire function with the code from Freemius dashboard
 * after you create your product there.
 */
if ( !function_exists( 'aichat_fs' ) ) {
    /**
     * Freemius instance getter
     * 
     * @return Freemius|null
     */
    function aichat_fs() {
        global $aichat_fs;
        if ( !isset( $aichat_fs ) ) {
            // Check if Freemius SDK exists
            $freemius_path = AICHAT_PLUGIN_DIR . 'vendor/freemius/start.php';
            if ( !file_exists( $freemius_path ) ) {
                // SDK not installed yet - return null
                return null;
            }
            // Include Freemius SDK
            require_once $freemius_path;
            // Initialize Freemius
            // REPLACE THIS WITH YOUR FREEMIUS INIT CODE FROM DASHBOARD
            $aichat_fs = fs_dynamic_init( array(
                'id'               => '23266',
                'slug'             => 'axiachat-ai',
                'type'             => 'plugin',
                'public_key'       => 'pk_a47fc9c694b6ba7bc2b2d17313a1d',
                'is_premium'       => false,
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'menu'             => array(
                    'slug'    => 'aichat-settings',
                    'contact' => false,
                    'support' => true,
                    'pricing' => false,
                    'parent'  => array(
                        'slug' => 'aichat-settings',
                    ),
                ),
                'is_live'          => true,
                'is_org_compliant' => true,
            ) );
        }
        return $aichat_fs;
    }

    // Initialize Freemius
    $aichat_fs_instance = aichat_fs();
    // Signal that SDK was initiated
    if ( $aichat_fs_instance ) {
        // Hook into Freemius uninstall flow to clean up plugin data when opted in
        $aichat_fs_instance->add_action( 'after_uninstall', 'aichat_do_cleanup' );
        // Replace default "Support Forum" label with "Get Support" and point to WP.org forum
        $aichat_fs_instance->override_i18n( array(
            'support-forum' => 'Get Support',
        ) );
        $aichat_fs_instance->add_filter( 'support_forum_url', function ( $url ) {
            return 'https://wordpress.org/support/plugin/axiachat-ai/';
        } );
        do_action( 'aichat_fs_loaded' );
    }
}
// end is__premium_only