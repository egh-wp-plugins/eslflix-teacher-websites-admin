<?php
/**
 * Plugin Name:       ESLFlix Teacher Websites Admin
 * Description:       Prepare teacher website accounts, issue single-use builder codes, reset passwords, and manage requested domains.
 * Version:           1.4.2
 * Author:            ESLFlix
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESLFLIX_TWA_VERSION', '1.4.2' );
define( 'ESLFLIX_TWA_CODE_HASH_META', 'teacher_builder_code_hash' );
define( 'ESLFLIX_TWA_CODE_CREATED_META', 'teacher_builder_code_created_at' );
define( 'ESLFLIX_TWA_CODE_USED_META', 'teacher_builder_code_used_at' );
define( 'ESLFLIX_TWA_ACCESS_META', 'teacher_builder_access_granted' );
define( 'ESLFLIX_TWA_DOMAIN_TYPE_META', 'teacher_builder_domain_type' );
define( 'ESLFLIX_TWA_CUSTOM_DOMAIN_META', 'teacher_builder_custom_domain' );
define( 'ESLFLIX_TWA_CONNECTED_DOMAIN_META', 'teacher_builder_connected_domain' );
define( 'ESLFLIX_TWA_CAPABILITY', 'manage_teacher_websites' );
define( 'ESLFLIX_TWA_SITE_BASE_URL', 'https://teacher-sites.english-grammar-homework.com/' );
define( 'ESLFLIX_TWA_RECURRING_TOPUP_HOOK', 'eslflix_teacher_recurring_calendar_topup' );

/**
 * Load the teacher-site provisioning runtime for WordPress Cron requests.
 *
 * The teacher builder is a sibling application rather than a WordPress
 * plugin, so WordPress Cron does not normally load its hook callbacks.
 */
function eslflix_twa_load_teacher_builder_runtime() {
    if ( function_exists( 'teacher_builder_provision_reserved_subdomain' ) ) {
        return true;
    }

    $domains_root = dirname( dirname( untrailingslashit( ABSPATH ) ) );
    $runtime = $domains_root . '/teacher-sites.english-grammar-homework.com/public_html/includes/bootstrap.php';
    if ( ! is_readable( $runtime ) ) {
        error_log( 'ESLFlix Teacher Websites: provisioning runtime could not be loaded.' );
        return false;
    }

    require_once $runtime;
    return function_exists( 'teacher_builder_provision_reserved_subdomain' );
}

/**
 * Continue a teacher subdomain setup from a normal WordPress Cron request.
 */
function eslflix_twa_retry_domain_provisioning( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 || ! eslflix_twa_load_teacher_builder_runtime() ) {
        return;
    }

    teacher_builder_provision_reserved_subdomain( $user_id );
}
add_action( 'teacher_builder_retry_domain_provisioning', 'eslflix_twa_retry_domain_provisioning', 10, 1 );

/**
 * Restore missing retry events for unfinished subdomain setups.
 */
function eslflix_twa_restore_domain_provisioning_retries() {
    if ( get_transient( 'eslflix_twa_provisioning_retry_watchdog' ) ) {
        return;
    }
    set_transient( 'eslflix_twa_provisioning_retry_watchdog', '1', MINUTE_IN_SECONDS );

    global $wpdb;
    $table = $wpdb->prefix . 'teacher_websites';
    $pending_user_ids = $wpdb->get_col(
        "SELECT user_id
         FROM {$table}
         WHERE subdomain_locked = 1
           AND provisioning_status IN ('reserved', 'provisioning', 'waiting_ssl', 'failed')
           AND provisioning_attempts < 20
         ORDER BY updated_at ASC
         LIMIT 20"
    );

    foreach ( $pending_user_ids as $pending_user_id ) {
        $args = [ absint( $pending_user_id ) ];
        if ( ! wp_next_scheduled( 'teacher_builder_retry_domain_provisioning', $args ) ) {
            wp_schedule_single_event(
                time() + MINUTE_IN_SECONDS,
                'teacher_builder_retry_domain_provisioning',
                $args
            );
        }
    }
}
add_action( 'init', 'eslflix_twa_restore_domain_provisioning_retries', 30 );

/**
 * Grant teacher-website administration to WordPress administrators and the
 * two existing limited ESLFlix admin accounts.
 */
function eslflix_twa_bootstrap_access() {
    $administrator = get_role( 'administrator' );
    if ( $administrator && ! $administrator->has_cap( ESLFLIX_TWA_CAPABILITY ) ) {
        $administrator->add_cap( ESLFLIX_TWA_CAPABILITY );
    }

    foreach ( [ 90, 151 ] as $approved_user_id ) {
        $approved_user = get_user_by( 'id', $approved_user_id );
        if ( $approved_user instanceof WP_User && ! $approved_user->has_cap( ESLFLIX_TWA_CAPABILITY ) ) {
            $approved_user->add_cap( ESLFLIX_TWA_CAPABILITY );
        }
    }
}
add_action( 'init', 'eslflix_twa_bootstrap_access', 20 );

/**
 * Central access check for this sensitive admin tool.
 */
function eslflix_twa_user_can_manage() {
    return current_user_can( 'manage_options' ) || current_user_can( ESLFLIX_TWA_CAPABILITY );
}

/**
 * Normalize a builder code before hashing or checking it.
 */
function eslflix_teacher_websites_normalize_builder_code( $code ) {
    $code = strtoupper( trim( (string) $code ) );
    return preg_replace( '/[^A-Z0-9]/', '', $code );
}

/**
 * Verify a submitted builder code for a specific WordPress user.
 */
function eslflix_teacher_websites_verify_builder_code( $user_id, $submitted_code ) {
    $user_id = absint( $user_id );
    $hash = (string) get_user_meta( $user_id, ESLFLIX_TWA_CODE_HASH_META, true );
    $normalized_code = eslflix_teacher_websites_normalize_builder_code( $submitted_code );

    if ( $user_id < 1 || $hash === '' || $normalized_code === '' ) {
        return false;
    }

    return wp_check_password( $normalized_code, $hash, $user_id );
}

/**
 * Remove a single-use builder code after it has unlocked the account.
 */
function eslflix_teacher_websites_mark_builder_code_used( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id < 1 ) {
        return;
    }

    delete_user_meta( $user_id, ESLFLIX_TWA_CODE_HASH_META );
    delete_user_meta( $user_id, ESLFLIX_TWA_CODE_CREATED_META );
    update_user_meta( $user_id, ESLFLIX_TWA_CODE_USED_META, current_time( 'mysql' ) );
}

/**
 * Register the WordPress admin page.
 */
function eslflix_twa_register_admin_menu() {
    add_menu_page(
        'Teacher Websites',
        'Teacher Websites',
        ESLFLIX_TWA_CAPABILITY,
        'eslflix-teacher-websites',
        'eslflix_twa_render_admin_page',
        'dashicons-admin-site-alt3',
        4
    );
}
add_action( 'admin_menu', 'eslflix_twa_register_admin_menu' );

/**
 * Load the page-specific admin interface.
 */
function eslflix_twa_enqueue_admin_assets( $hook_suffix ) {
    if ( 'toplevel_page_eslflix-teacher-websites' !== $hook_suffix ) {
        return;
    }

    $plugin_path = plugin_dir_path( __FILE__ );
    wp_enqueue_style(
        'eslflix-twa-admin',
        plugin_dir_url( __FILE__ ) . 'assets/admin.css',
        [],
        filemtime( $plugin_path . 'assets/admin.css' )
    );
    wp_enqueue_script(
        'eslflix-twa-admin',
        plugin_dir_url( __FILE__ ) . 'assets/admin.js',
        [],
        filemtime( $plugin_path . 'assets/admin.js' ),
        true
    );
}
add_action( 'admin_enqueue_scripts', 'eslflix_twa_enqueue_admin_assets' );

/**
 * Produce a readable, high-entropy code without ambiguous characters.
 */
function eslflix_twa_generate_builder_code() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $random = '';
    $last_index = strlen( $alphabet ) - 1;

    for ( $index = 0; $index < 8; $index++ ) {
        $random .= $alphabet[ random_int( 0, $last_index ) ];
    }

    return 'ESL-' . substr( $random, 0, 4 ) . '-' . substr( $random, 4, 4 );
}

/**
 * Store a one-time admin notice without placing secrets in the URL.
 */
function eslflix_twa_set_admin_notice( $notice ) {
    set_transient(
        'eslflix_twa_notice_' . get_current_user_id(),
        $notice,
        5 * MINUTE_IN_SECONDS
    );
}

/**
 * Read and immediately destroy the current admin's one-time notice.
 */
function eslflix_twa_take_admin_notice() {
    $key = 'eslflix_twa_notice_' . get_current_user_id();
    $notice = get_transient( $key );
    delete_transient( $key );
    return is_array( $notice ) ? $notice : null;
}

/**
 * Return the profile table name used by the teacher-site application.
 */
function eslflix_twa_profile_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'teacher_websites';
}

/**
 * Check whether the teacher profile table exists.
 */
function eslflix_twa_profile_table_exists() {
    global $wpdb;
    $table = eslflix_twa_profile_table_name();
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Change whether a teacher profile is publicly available on its connected domain.
 */
function eslflix_twa_set_profile_published( $user_id, $published ) {
    global $wpdb;
    if ( ! eslflix_twa_profile_table_exists() ) {
        return false;
    }

    return false !== $wpdb->update(
        eslflix_twa_profile_table_name(),
        [
            'published'  => $published ? 1 : 0,
            'updated_at' => current_time( 'mysql' ),
        ],
        [ 'user_id' => absint( $user_id ) ],
        [ '%d', '%s' ],
        [ '%d' ]
    );
}

/**
 * Return the requested ESLFlix domain for a profile record.
 */
function eslflix_twa_requested_domain( $profile ) {
    $subdomain = is_array( $profile ) ? sanitize_title( (string) ( $profile['subdomain'] ?? '' ) ) : '';
    return $subdomain !== '' ? $subdomain . '.eslflix.com' : '';
}

/**
 * Return the domain that should be connected for a teacher.
 */
function eslflix_twa_preferred_domain( $user_id, $profile ) {
    $custom_domain = strtolower( trim( (string) get_user_meta( $user_id, ESLFLIX_TWA_CUSTOM_DOMAIN_META, true ) ) );
    return $custom_domain !== '' ? $custom_domain : eslflix_twa_requested_domain( $profile );
}

/**
 * Return the public HTTPS URL for a connected teacher domain.
 */
function eslflix_twa_public_site_url( $domain ) {
    $domain = strtolower( trim( (string) $domain ) );
    return $domain !== '' ? esc_url_raw( 'https://' . $domain . '/' ) : '';
}

/**
 * Use the same sender details as the ESLFlix exercise-generator emails.
 */
function eslflix_twa_mail_from() {
    return 'info@eslflix.com';
}

function eslflix_twa_mail_from_name() {
    return 'ESLFlix';
}

function eslflix_twa_mail_content_type() {
    return 'text/html';
}

/**
 * Load the shared teacher-calendar helpers for recurring-series expansion.
 */
function eslflix_twa_load_teacher_calendar_runtime() {
    if ( function_exists( 'teacher_calendar_top_up_all_recurring_series' ) ) {
        return true;
    }
    $runtime_file = dirname( ABSPATH, 2 )
        . '/teacher-sites.english-grammar-homework.com/public_html/includes/calendar.php';
    if ( ! is_readable( $runtime_file ) ) {
        return false;
    }
    require_once $runtime_file;
    return function_exists( 'teacher_calendar_top_up_all_recurring_series' );
}

/**
 * Keep ongoing weekly series populated roughly three months ahead.
 */
function eslflix_twa_top_up_recurring_calendars() {
    global $wpdb;
    if ( ! eslflix_twa_load_teacher_calendar_runtime() ) {
        return;
    }
    $user_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
            'teacher_calendar_recurring_series'
        )
    );
    foreach ( array_map( 'absint', (array) $user_ids ) as $user_id ) {
        if ( $user_id > 0 ) {
            teacher_calendar_top_up_all_recurring_series( $user_id );
        }
    }
}
add_action( ESLFLIX_TWA_RECURRING_TOPUP_HOOK, 'eslflix_twa_top_up_recurring_calendars' );

/**
 * Activation hooks do not rerun on file-only updates, so register the daily
 * event lazily on the next normal WordPress request.
 */
function eslflix_twa_ensure_recurring_topup_event() {
    if ( ! wp_next_scheduled( ESLFLIX_TWA_RECURRING_TOPUP_HOOK ) ) {
        wp_schedule_event(
            time() + ( 5 * MINUTE_IN_SECONDS ),
            'daily',
            ESLFLIX_TWA_RECURRING_TOPUP_HOOK
        );
    }
}
add_action( 'init', 'eslflix_twa_ensure_recurring_topup_event', 30 );

/**
 * Tell a teacher that their connected public website is ready to share.
 */
function eslflix_twa_send_website_ready_email( $user, $domain ) {
    if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
        return false;
    }

    $site_url = eslflix_twa_public_site_url( $domain );
    if ( $site_url === '' ) {
        return false;
    }

    $teacher_name = trim( (string) ( $user->display_name ?: $user->user_login ) );
    $subject = 'Your ESLFlix teacher website is ready!';
    $button_style = 'background-color: #E50914; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold; display: inline-block; box-shadow: 0 4px 6px rgba(0,0,0,0.12);';
    $logo_html = '<div style="text-align: center; margin-bottom: 20px;"><div style="font-family: Arial, sans-serif; font-size: 32px; font-weight: 900; letter-spacing: 1px; color: #333333; display: inline-block;"><span style="color: #E50914;">ESL</span>Flix</div></div>';

    $body = '<div style="background-color: #f4f4f6; padding: 28px 12px;">';
    $body .= '<div style="font-family: Arial, sans-serif; color: #333333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 26px; background-color: #ffffff; border: 1px solid #eeeeee; border-radius: 8px;">';
    $body .= $logo_html;
    $body .= '<h2 style="color: #E50914; border-bottom: 2px solid #E50914; padding-bottom: 10px; margin-top: 0;">Your teacher website is ready!</h2>';
    $body .= '<p>Hi ' . esc_html( $teacher_name ) . ',</p>';
    $body .= '<p>Your ESLFlix teacher website has been connected and is now ready to share with your students.</p>';
    $body .= '<div style="margin: 28px 0; padding: 18px 20px; background-color: #fff4f4; border: 1px solid #f3c1c4; border-radius: 8px; text-align: center;">';
    $body .= '<div style="font-size: 12px; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; color: #E50914; margin-bottom: 6px;">Your website address</div>';
    $body .= '<a href="' . esc_url( $site_url ) . '" style="color: #333333; font-size: 18px; font-weight: bold; text-decoration: none; word-break: break-all;">' . esc_html( $domain ) . '</a>';
    $body .= '</div>';
    $body .= '<div style="margin: 30px 0; text-align: center;">';
    $body .= '<a href="' . esc_url( $site_url ) . '" style="' . $button_style . '">View Your Website</a>';
    $body .= '</div>';
    $body .= '<p>You can continue using the website builder whenever you want to update your profile, prices, availability, reviews, or other website details.</p>';
    $body .= '<p style="font-size: 13px; color: #777777;">If the button does not open, copy this address into your browser:<br><a href="' . esc_url( $site_url ) . '" style="color: #E50914; word-break: break-all;">' . esc_html( $site_url ) . '</a></p>';
    $body .= '<p style="margin-top: 30px; text-align: center;">Lloyd<br><strong><span style="color: #E50914;">ESL</span>Flix</strong></p>';
    $body .= '</div>';
    $body .= '</div>';

    add_filter( 'wp_mail_from', 'eslflix_twa_mail_from' );
    add_filter( 'wp_mail_from_name', 'eslflix_twa_mail_from_name' );
    add_filter( 'wp_mail_content_type', 'eslflix_twa_mail_content_type' );

    $sent = wp_mail( $user->user_email, $subject, $body );

    remove_filter( 'wp_mail_from', 'eslflix_twa_mail_from' );
    remove_filter( 'wp_mail_from_name', 'eslflix_twa_mail_from_name' );
    remove_filter( 'wp_mail_content_type', 'eslflix_twa_mail_content_type' );

    return (bool) $sent;
}

/**
 * Normalize an admin-entered custom domain.
 */
function eslflix_twa_normalize_domain( $value ) {
    $value = strtolower( trim( (string) $value ) );
    if ( $value === '' ) {
        return '';
    }

    if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
        $value = 'https://' . $value;
    }

    $host = (string) wp_parse_url( $value, PHP_URL_HOST );
    $host = strtolower( rtrim( $host, '.' ) );
    if ( function_exists( 'idn_to_ascii' ) ) {
        $ascii_host = idn_to_ascii( $host, IDNA_DEFAULT );
        if ( is_string( $ascii_host ) && $ascii_host !== '' ) {
            $host = strtolower( $ascii_host );
        }
    }

    if (
        $host === ''
        || strlen( $host ) > 253
        || ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME )
    ) {
        return new WP_Error( 'invalid_domain', 'Enter a valid domain name without a page path.' );
    }

    return $host;
}

/**
 * Redirect back to the plugin page after a protected admin action.
 */
function eslflix_twa_redirect_after_action( $user_id = 0 ) {
    $args = [ 'page' => 'eslflix-teacher-websites' ];
    $search = isset( $_POST['return_search'] ) ? sanitize_text_field( wp_unslash( $_POST['return_search'] ) ) : '';
    if ( $search !== '' ) {
        $args['s'] = $search;
    }
    if ( $user_id ) {
        $args['teacher'] = absint( $user_id );
    }

    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Handle code generation, password resets, and custom-domain updates.
 */
function eslflix_twa_handle_admin_action() {
    if ( ! eslflix_twa_user_can_manage() ) {
        wp_die(
            esc_html__( 'You are not allowed to manage teacher websites.', 'eslflix-twa' ),
            '',
            [ 'response' => 403 ]
        );
    }

    check_admin_referer( 'eslflix_twa_admin_action' );

    $requested_action = isset( $_POST['teacher_action'] ) ? sanitize_key( wp_unslash( $_POST['teacher_action'] ) ) : '';
    $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
    $user = $user_id ? get_user_by( 'id', $user_id ) : false;

    if ( ! $user instanceof WP_User ) {
        eslflix_twa_set_admin_notice(
            [
                'type'    => 'error',
                'message' => 'The selected teacher account could not be found.',
            ]
        );
        eslflix_twa_redirect_after_action();
    }

    if ( 'generate_code' === $requested_action ) {
        $domain_type = isset( $_POST['domain_type'] )
            ? sanitize_key( wp_unslash( $_POST['domain_type'] ) )
            : 'subdomain';
        if ( ! in_array( $domain_type, [ 'subdomain', 'top_level' ], true ) ) {
            eslflix_twa_set_admin_notice(
                [
                    'type'    => 'error',
                    'message' => 'Choose whether this builder code includes an ESLFlix subdomain or top-level domain support.',
                ]
            );
            eslflix_twa_redirect_after_action( $user_id );
        }

        $builder_code = eslflix_twa_generate_builder_code();
        $normalized_code = eslflix_teacher_websites_normalize_builder_code( $builder_code );
        $domain_label = 'top_level' === $domain_type ? 'top-level domain' : 'ESLFlix subdomain';

        update_user_meta( $user_id, ESLFLIX_TWA_CODE_HASH_META, wp_hash_password( $normalized_code ) );
        update_user_meta( $user_id, ESLFLIX_TWA_CODE_CREATED_META, current_time( 'mysql' ) );
        update_user_meta( $user_id, ESLFLIX_TWA_ACCESS_META, '0' );
        update_user_meta( $user_id, ESLFLIX_TWA_DOMAIN_TYPE_META, $domain_type );
        delete_user_meta( $user_id, ESLFLIX_TWA_CODE_USED_META );

        eslflix_twa_set_admin_notice(
            [
                'type'         => 'success',
                'message'      => sprintf( 'A new single-use builder code with %1$s support was generated for %2$s.', $domain_label, $user->display_name ),
                'secret_label' => sprintf( 'Builder code · %s', $domain_label ),
                'secret'       => $builder_code,
                'warning'      => 'Copy this code now. It is stored securely and cannot be displayed again.',
            ]
        );
        eslflix_twa_redirect_after_action( $user_id );
    }

    if ( 'reset_password' === $requested_action ) {
        if ( get_current_user_id() === $user_id ) {
            eslflix_twa_set_admin_notice(
                [
                    'type'    => 'error',
                    'message' => 'For safety, this page cannot reset the password of the administrator currently using it.',
                ]
            );
            eslflix_twa_redirect_after_action( $user_id );
        }

        $new_password = wp_generate_password( 18, true, false );
        wp_set_password( $new_password, $user_id );

        eslflix_twa_set_admin_notice(
            [
                'type'         => 'success',
                'message'      => sprintf( 'The WordPress password for %s was reset.', $user->display_name ),
                'secret_label' => 'Temporary password',
                'secret'       => $new_password,
                'warning'      => 'Copy this password now and send it to the teacher manually. It cannot be displayed again.',
            ]
        );
        eslflix_twa_redirect_after_action( $user_id );
    }

    if ( 'save_domain' === $requested_action ) {
        $raw_domain = isset( $_POST['custom_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_domain'] ) ) : '';
        $domain = eslflix_twa_normalize_domain( $raw_domain );

        if ( is_wp_error( $domain ) ) {
            eslflix_twa_set_admin_notice(
                [
                    'type'    => 'error',
                    'message' => $domain->get_error_message(),
                ]
            );
            eslflix_twa_redirect_after_action( $user_id );
        }

        if ( $domain !== '' ) {
            $duplicate_users = get_users(
                [
                    'meta_key'    => ESLFLIX_TWA_CUSTOM_DOMAIN_META,
                    'meta_value'  => $domain,
                    'exclude'     => [ $user_id ],
                    'fields'      => 'ids',
                    'number'      => 1,
                    'count_total' => false,
                ]
            );
            if ( $duplicate_users ) {
                eslflix_twa_set_admin_notice(
                    [
                        'type'    => 'error',
                        'message' => 'That custom domain is already assigned to another teacher.',
                    ]
                );
                eslflix_twa_redirect_after_action( $user_id );
            }
            update_user_meta( $user_id, ESLFLIX_TWA_CUSTOM_DOMAIN_META, $domain );
        } else {
            delete_user_meta( $user_id, ESLFLIX_TWA_CUSTOM_DOMAIN_META );
        }

        $profile_records = eslflix_twa_get_profile_records();
        $preferred_domain = eslflix_twa_preferred_domain( $user_id, $profile_records[ $user_id ] ?? null );
        $connected_domain = strtolower( trim( (string) get_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META, true ) ) );
        if ( $connected_domain !== '' && $connected_domain !== $preferred_domain ) {
            delete_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META );
            eslflix_twa_set_profile_published( $user_id, false );
        }

        eslflix_twa_set_admin_notice(
            [
                'type'    => 'success',
                'message' => $domain === ''
                    ? sprintf( 'The custom domain was cleared for %s.', $user->display_name )
                    : sprintf( '%s is now recorded for %s.', $domain, $user->display_name ),
            ]
        );
        eslflix_twa_redirect_after_action( $user_id );
    }

    if ( 'set_domain_connected' === $requested_action ) {
        $profile_records = eslflix_twa_get_profile_records();
        $profile = $profile_records[ $user_id ] ?? null;
        $preferred_domain = eslflix_twa_preferred_domain( $user_id, $profile );
        $should_connect = isset( $_POST['connected'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['connected'] ) );

        if ( $should_connect && ( $preferred_domain === '' || ! $profile ) ) {
            eslflix_twa_set_admin_notice(
                [
                    'type'    => 'error',
                    'message' => $preferred_domain === ''
                        ? 'A requested subdomain or custom domain is required before it can be confirmed as connected.'
                        : 'The teacher must open the builder once before a domain can be connected.',
                ]
            );
            eslflix_twa_redirect_after_action( $user_id );
        }

        if ( $should_connect ) {
            update_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META, $preferred_domain );
            eslflix_twa_set_profile_published( $user_id, true );

            $updated_profiles = eslflix_twa_get_profile_records();
            $saved_domain = strtolower( trim( (string) get_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META, true ) ) );
            $connection_is_ready = (
                $saved_domain === $preferred_domain
                && ! empty( $updated_profiles[ $user_id ]['published'] )
            );

            if ( ! $connection_is_ready ) {
                delete_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META );
                eslflix_twa_set_profile_published( $user_id, false );
                eslflix_twa_set_admin_notice(
                    [
                        'type'    => 'error',
                        'message' => 'The domain connection could not be saved. No website-ready email was sent.',
                    ]
                );
                eslflix_twa_redirect_after_action( $user_id );
            }

            $email_sent = eslflix_twa_send_website_ready_email( $user, $preferred_domain );
        } else {
            delete_user_meta( $user_id, ESLFLIX_TWA_CONNECTED_DOMAIN_META );
            eslflix_twa_set_profile_published( $user_id, false );
            $email_sent = null;
        }

        $notice = [
            'type'    => 'success',
            'message' => $should_connect
                ? sprintf(
                    '%s is confirmed as connected for %s. The website-ready email was sent to %s.',
                    $preferred_domain,
                    $user->display_name,
                    $user->user_email
                )
                : sprintf( 'The public website link was disabled for %s.', $user->display_name ),
        ];
        if ( $should_connect && ! $email_sent ) {
            $notice['message'] = sprintf( '%s is confirmed as connected for %s.', $preferred_domain, $user->display_name );
            $notice['warning'] = sprintf(
                'WordPress could not send the website-ready email to %s. The website remains connected.',
                $user->user_email
            );
        }
        eslflix_twa_set_admin_notice( $notice );
        eslflix_twa_redirect_after_action( $user_id );
    }

    eslflix_twa_set_admin_notice(
        [
            'type'    => 'error',
            'message' => 'Unknown teacher website action.',
        ]
    );
    eslflix_twa_redirect_after_action( $user_id );
}
add_action( 'admin_post_eslflix_twa_action', 'eslflix_twa_handle_admin_action' );

/**
 * Search by exact user ID or partial username, email, and display name.
 */
function eslflix_twa_search_users( $search ) {
    $search = trim( (string) $search );
    if ( $search === '' ) {
        return [];
    }

    $users = [];
    if ( ctype_digit( $search ) ) {
        $user_by_id = get_user_by( 'id', absint( $search ) );
        if ( $user_by_id instanceof WP_User ) {
            $users[ $user_by_id->ID ] = $user_by_id;
        }
    }

    $user_query = new WP_User_Query(
        [
            'search'         => '*' . $search . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 25,
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ]
    );

    foreach ( $user_query->get_results() as $user ) {
        if ( $user instanceof WP_User ) {
            $users[ $user->ID ] = $user;
        }
    }

    return array_values( $users );
}

/**
 * Load profile records once for the admin page.
 */
function eslflix_twa_get_profile_records() {
    global $wpdb;
    if ( ! eslflix_twa_profile_table_exists() ) {
        return [];
    }

    $table = eslflix_twa_profile_table_name();
    $rows = $wpdb->get_results(
        "SELECT id, user_id, slug, subdomain, published, updated_at FROM {$table} ORDER BY updated_at DESC",
        ARRAY_A
    );

    $records = [];
    foreach ( (array) $rows as $row ) {
        $records[ absint( $row['user_id'] ) ] = [
            'id'         => absint( $row['id'] ),
            'slug'       => sanitize_title( (string) $row['slug'] ),
            'subdomain'  => sanitize_text_field( (string) $row['subdomain'] ),
            'published'  => ! empty( $row['published'] ),
            'updated_at' => sanitize_text_field( (string) $row['updated_at'] ),
        ];
    }
    return $records;
}

/**
 * Find all users who have been prepared for, or started, a teacher website.
 */
function eslflix_twa_get_ready_teachers( $profile_records ) {
    global $wpdb;

    $meta_keys = [
        ESLFLIX_TWA_CODE_HASH_META,
        ESLFLIX_TWA_ACCESS_META,
        ESLFLIX_TWA_CUSTOM_DOMAIN_META,
        ESLFLIX_TWA_CONNECTED_DOMAIN_META,
    ];
    $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
    $sql = $wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
        ...$meta_keys
    );
    $user_ids = array_map( 'absint', (array) $wpdb->get_col( $sql ) );
    $user_ids = array_values( array_unique( array_merge( $user_ids, array_map( 'absint', array_keys( $profile_records ) ) ) ) );

    if ( ! $user_ids ) {
        return [];
    }

    $users = get_users(
        [
            'include' => $user_ids,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 250,
        ]
    );
    return array_values( array_filter( $users, static fn( $user ) => $user instanceof WP_User ) );
}

/**
 * Return a presentation status for a teacher website account.
 */
function eslflix_twa_get_teacher_status( $user_id, $profile ) {
    if ( (string) get_user_meta( $user_id, ESLFLIX_TWA_ACCESS_META, true ) === '1' ) {
        return [ 'label' => 'Builder unlocked', 'class' => 'is-active' ];
    }
    if ( (string) get_user_meta( $user_id, ESLFLIX_TWA_CODE_HASH_META, true ) !== '' ) {
        return [ 'label' => 'Waiting for code', 'class' => 'is-waiting' ];
    }
    if ( $profile ) {
        return [ 'label' => 'Profile started', 'class' => 'is-started' ];
    }
    return [ 'label' => 'Not prepared', 'class' => 'is-empty' ];
}

/**
 * Render the protected form fields shared by every action.
 */
function eslflix_twa_render_action_fields( $user_id, $action, $search = '' ) {
    ?>
    <input type="hidden" name="action" value="eslflix_twa_action">
    <input type="hidden" name="teacher_action" value="<?php echo esc_attr( $action ); ?>">
    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
    <input type="hidden" name="return_search" value="<?php echo esc_attr( $search ); ?>">
    <?php wp_nonce_field( 'eslflix_twa_admin_action' ); ?>
    <?php
}

/**
 * Render the admin search result for one user.
 */
function eslflix_twa_render_search_result( $user, $search, $profile_records ) {
    $profile = $profile_records[ $user->ID ] ?? null;
    $status = eslflix_twa_get_teacher_status( $user->ID, $profile );
    $has_access = (string) get_user_meta( $user->ID, ESLFLIX_TWA_ACCESS_META, true ) === '1';
    $has_code = (string) get_user_meta( $user->ID, ESLFLIX_TWA_CODE_HASH_META, true ) !== '';
    $replacement_code = $has_access || $has_code;
    ?>
    <article class="eslflix-twa-user-card">
        <div class="eslflix-twa-avatar"><?php echo get_avatar( $user->ID, 64 ); ?></div>
        <div class="eslflix-twa-user-identity">
            <span class="eslflix-twa-status <?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
            <h3><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></h3>
            <p><strong>@<?php echo esc_html( $user->user_login ); ?></strong> · ID <?php echo esc_html( $user->ID ); ?></p>
            <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
        </div>
        <div class="eslflix-twa-user-actions">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eslflix-twa-code-form">
                <?php eslflix_twa_render_action_fields( $user->ID, 'generate_code', $search ); ?>
                <span>Generate builder code for:</span>
                <div class="eslflix-twa-code-choices">
                    <button
                        type="submit"
                        name="domain_type"
                        value="subdomain"
                        class="button button-primary<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                        <?php if ( $replacement_code ) : ?>
                            data-confirm="<?php echo esc_attr( $has_access ? 'Generate a new subdomain builder code? This will lock the teacher out until they enter it.' : 'Replace the waiting code with a subdomain builder code?' ); ?>"
                        <?php endif; ?>
                    >
                        <?php echo $replacement_code ? 'New subdomain code' : 'Generate subdomain code'; ?>
                    </button>
                    <button
                        type="submit"
                        name="domain_type"
                        value="top_level"
                        class="button eslflix-twa-top-level-button<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                        <?php if ( $replacement_code ) : ?>
                            data-confirm="<?php echo esc_attr( $has_access ? 'Generate a new top-level domain builder code? This will lock the teacher out until they enter it.' : 'Replace the waiting code with a top-level domain builder code?' ); ?>"
                        <?php endif; ?>
                    >
                        <?php echo $replacement_code ? 'New top-level code' : 'Generate top-level domain code'; ?>
                    </button>
                </div>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php eslflix_twa_render_action_fields( $user->ID, 'reset_password', $search ); ?>
                <button
                    type="submit"
                    class="button eslflix-twa-requires-confirmation"
                    data-confirm="Reset the WordPress password for <?php echo esc_attr( $user->display_name ?: $user->user_login ); ?>? Their existing password will stop working immediately."
                >Reset password</button>
            </form>
        </div>
    </article>
    <?php
}

/**
 * Render the one-time secret notice generated by an admin action.
 */
function eslflix_twa_render_notice( $notice ) {
    if ( ! $notice ) {
        return;
    }
    $type = ( $notice['type'] ?? '' ) === 'error' ? 'is-error' : 'is-success';
    ?>
    <section class="eslflix-twa-notice <?php echo esc_attr( $type ); ?>" role="status">
        <div>
            <strong><?php echo esc_html( $notice['message'] ?? '' ); ?></strong>
            <?php if ( ! empty( $notice['warning'] ) ) : ?>
                <p><?php echo esc_html( $notice['warning'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php if ( ! empty( $notice['secret'] ) ) : ?>
            <div class="eslflix-twa-secret">
                <label><?php echo esc_html( $notice['secret_label'] ?? 'One-time value' ); ?></label>
                <div>
                    <input type="text" readonly value="<?php echo esc_attr( $notice['secret'] ); ?>" data-eslflix-secret>
                    <button type="button" class="button button-primary" data-copy-secret>Copy</button>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Render the complete teacher website administration screen.
 */
function eslflix_twa_render_admin_page() {
    if ( ! eslflix_twa_user_can_manage() ) {
        return;
    }

    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $search_results = eslflix_twa_search_users( $search );
    $profile_records = eslflix_twa_get_profile_records();
    $teachers = eslflix_twa_get_ready_teachers( $profile_records );
    $notice = eslflix_twa_take_admin_notice();
    ?>
    <div class="wrap eslflix-twa-wrap">
        <header class="eslflix-twa-hero">
            <div>
                <span class="eslflix-twa-brand"><b>ESL</b>Flix</span>
                <h1>Teacher Website Setup</h1>
                <p>Prepare access, recover accounts, and track the web address requested by each teacher.</p>
            </div>
            <a class="button" href="https://teacher-sites.english-grammar-homework.com/" target="_blank" rel="noopener noreferrer">Open website builder</a>
        </header>

        <?php eslflix_twa_render_notice( $notice ); ?>

        <section class="eslflix-twa-panel eslflix-twa-search-panel">
            <div class="eslflix-twa-panel-heading">
                <div>
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <div>
                        <h2>Find a teacher</h2>
                        <p>Search WordPress users by username, email address, display name, or numeric user ID.</p>
                    </div>
                </div>
            </div>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="eslflix-twa-search-form">
                <input type="hidden" name="page" value="eslflix-teacher-websites">
                <label class="screen-reader-text" for="eslflix-twa-search">Search teachers</label>
                <input id="eslflix-twa-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Username, email address, display name, or user ID">
                <button type="submit" class="button button-primary">Search teachers</button>
                <?php if ( $search !== '' ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=eslflix-teacher-websites' ) ); ?>">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ( $search !== '' ) : ?>
                <div class="eslflix-twa-search-results">
                    <?php if ( $search_results ) : ?>
                        <?php foreach ( $search_results as $result_user ) : ?>
                            <?php eslflix_twa_render_search_result( $result_user, $search, $profile_records ); ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="eslflix-twa-empty-state">
                            <span class="dashicons dashicons-businessperson" aria-hidden="true"></span>
                            <strong>No matching WordPress users</strong>
                            <p>Check the spelling or try the teacher's numeric user ID.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="eslflix-twa-panel">
            <div class="eslflix-twa-panel-heading">
                <div>
                    <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                    <div>
                        <h2>Teachers ready for website setup</h2>
                        <p>Teachers appear here after a code is generated, builder access is granted, or a website profile exists.</p>
                    </div>
                </div>
                <span class="eslflix-twa-count"><?php echo esc_html( count( $teachers ) ); ?> teachers</span>
            </div>

            <?php if ( $teachers ) : ?>
                <div class="eslflix-twa-table-wrap">
                    <table class="wp-list-table widefat fixed striped eslflix-twa-table">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Status</th>
                                <th>Website</th>
                                <th>Requested ESLFlix subdomain</th>
                                <th>Custom domain</th>
                                <th>Domain connection</th>
                                <th>Account actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $teachers as $teacher ) : ?>
                                <?php
                                $profile = $profile_records[ $teacher->ID ] ?? null;
                                $status = eslflix_twa_get_teacher_status( $teacher->ID, $profile );
                                $subdomain = $profile['subdomain'] ?? '';
                                $site_url = '';
                                $site_link_label = '';
                                $site_path = '';
                                if ( $profile && $profile['slug'] !== '' ) {
                                    $site_path = 'teacher/' . rawurlencode( $profile['slug'] ) . '/';
                                    $site_url = ESLFLIX_TWA_SITE_BASE_URL . $site_path;
                                    $site_link_label = 'View site';
                                }
                                $custom_domain = (string) get_user_meta( $teacher->ID, ESLFLIX_TWA_CUSTOM_DOMAIN_META, true );
                                $preferred_domain = eslflix_twa_preferred_domain( $teacher->ID, $profile );
                                $connected_domain = strtolower( trim( (string) get_user_meta( $teacher->ID, ESLFLIX_TWA_CONNECTED_DOMAIN_META, true ) ) );
                                $domain_is_connected = $preferred_domain !== '' && $connected_domain === $preferred_domain;
                                $has_access = (string) get_user_meta( $teacher->ID, ESLFLIX_TWA_ACCESS_META, true ) === '1';
                                $has_code = (string) get_user_meta( $teacher->ID, ESLFLIX_TWA_CODE_HASH_META, true ) !== '';
                                $replacement_code = $has_access || $has_code;
                                ?>
                                <tr>
                                    <td data-label="Teacher">
                                        <div class="eslflix-twa-table-user">
                                            <?php echo get_avatar( $teacher->ID, 42 ); ?>
                                            <div>
                                                <strong><?php echo esc_html( $teacher->display_name ?: $teacher->user_login ); ?></strong>
                                                <span>@<?php echo esc_html( $teacher->user_login ); ?> · WP ID <?php echo esc_html( $teacher->ID ); ?></span>
                                                <a href="mailto:<?php echo esc_attr( $teacher->user_email ); ?>"><?php echo esc_html( $teacher->user_email ); ?></a>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Status">
                                        <span class="eslflix-twa-status <?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
                                        <?php if ( $profile && $profile['published'] ) : ?>
                                            <span class="eslflix-twa-published">Published</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Website">
                                        <?php if ( $profile ) : ?>
                                            <div class="eslflix-twa-site-cell">
                                                <span class="eslflix-twa-site-id">#<?php echo esc_html( $profile['id'] ); ?></span>
                                                <?php if ( $site_url !== '' ) : ?>
                                                    <a class="eslflix-twa-view-site" href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo esc_html( $site_link_label ); ?>
                                                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                                    </a>
                                                    <small title="<?php echo esc_attr( $site_url ); ?>">/<?php echo esc_html( $site_path ); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else : ?>
                                            <span class="eslflix-twa-muted">Created after first builder visit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Requested subdomain">
                                        <?php if ( $subdomain !== '' ) : ?>
                                            <strong><?php echo esc_html( $subdomain ); ?>.eslflix.com</strong>
                                        <?php else : ?>
                                            <span class="eslflix-twa-muted">Not requested yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Custom domain">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eslflix-twa-domain-form">
                                            <?php eslflix_twa_render_action_fields( $teacher->ID, 'save_domain' ); ?>
                                            <input type="text" name="custom_domain" value="<?php echo esc_attr( $custom_domain ); ?>" placeholder="teacher-domain.com" aria-label="Custom domain for <?php echo esc_attr( $teacher->display_name ); ?>">
                                            <button type="submit" class="button">Save</button>
                                        </form>
                                    </td>
                                    <td data-label="Domain connection">
                                        <?php if ( $preferred_domain !== '' && $profile ) : ?>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eslflix-twa-connection-form">
                                                <?php eslflix_twa_render_action_fields( $teacher->ID, 'set_domain_connected' ); ?>
                                                <button
                                                    type="submit"
                                                    name="connected"
                                                    value="<?php echo $domain_is_connected ? '0' : '1'; ?>"
                                                    class="eslflix-twa-domain-toggle<?php echo $domain_is_connected ? ' is-connected' : ''; ?><?php echo $domain_is_connected ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                                                    aria-pressed="<?php echo $domain_is_connected ? 'true' : 'false'; ?>"
                                                    <?php if ( $domain_is_connected ) : ?>
                                                        data-confirm="Mark this domain as disconnected? The teacher's View website button will be disabled immediately."
                                                    <?php endif; ?>
                                                >
                                                    <span class="eslflix-twa-domain-toggle-track" aria-hidden="true"><i></i></span>
                                                    <span><?php echo $domain_is_connected ? 'Connected' : 'Confirm connected'; ?></span>
                                                </button>
                                                <small title="<?php echo esc_attr( $preferred_domain ); ?>"><?php echo esc_html( $preferred_domain ); ?></small>
                                            </form>
                                        <?php else : ?>
                                            <span class="eslflix-twa-muted"><?php echo $preferred_domain === '' ? 'No domain to connect' : 'Teacher has not opened builder'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Account actions">
                                        <div class="eslflix-twa-row-actions">
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eslflix-twa-code-form is-compact">
                                                <?php eslflix_twa_render_action_fields( $teacher->ID, 'generate_code' ); ?>
                                                <div class="eslflix-twa-code-choices">
                                                    <button
                                                        type="submit"
                                                        name="domain_type"
                                                        value="subdomain"
                                                        class="button<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                                                        <?php if ( $replacement_code ) : ?>
                                                            data-confirm="<?php echo esc_attr( $has_access ? 'Generate a new subdomain builder code? This will lock the teacher out until it is entered.' : 'Replace the waiting code with a subdomain builder code?' ); ?>"
                                                        <?php endif; ?>
                                                    ><?php echo $replacement_code ? 'New subdomain code' : 'Subdomain code'; ?></button>
                                                    <button
                                                        type="submit"
                                                        name="domain_type"
                                                        value="top_level"
                                                        class="button eslflix-twa-top-level-button<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                                                        <?php if ( $replacement_code ) : ?>
                                                            data-confirm="<?php echo esc_attr( $has_access ? 'Generate a new top-level domain builder code? This will lock the teacher out until it is entered.' : 'Replace the waiting code with a top-level domain builder code?' ); ?>"
                                                        <?php endif; ?>
                                                    ><?php echo $replacement_code ? 'New top-level code' : 'Top-level code'; ?></button>
                                                </div>
                                            </form>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                <?php eslflix_twa_render_action_fields( $teacher->ID, 'reset_password' ); ?>
                                                <button
                                                    type="submit"
                                                    class="button eslflix-twa-danger-button eslflix-twa-requires-confirmation"
                                                    data-confirm="Reset this teacher's WordPress password? Their current password will stop working immediately."
                                                >Reset password</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="eslflix-twa-empty-state">
                    <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                    <strong>No teachers are waiting for website setup</strong>
                    <p>Find a WordPress user above and generate their first builder code.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}
