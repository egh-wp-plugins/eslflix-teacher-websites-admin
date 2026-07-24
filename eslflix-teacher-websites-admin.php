<?php
/**
 * Plugin Name:       ESLFlix Teacher Websites Admin
 * Description:       Prepare teacher website accounts, issue single-use builder codes, reset passwords, and manage requested domains.
 * Version:           1.0.2
 * Author:            ESLFlix
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESLFLIX_TWA_VERSION', '1.0.2' );
define( 'ESLFLIX_TWA_CODE_HASH_META', 'teacher_builder_code_hash' );
define( 'ESLFLIX_TWA_CODE_CREATED_META', 'teacher_builder_code_created_at' );
define( 'ESLFLIX_TWA_CODE_USED_META', 'teacher_builder_code_used_at' );
define( 'ESLFLIX_TWA_ACCESS_META', 'teacher_builder_access_granted' );
define( 'ESLFLIX_TWA_CUSTOM_DOMAIN_META', 'teacher_builder_custom_domain' );
define( 'ESLFLIX_TWA_CAPABILITY', 'manage_teacher_websites' );
define( 'ESLFLIX_TWA_SITE_BASE_URL', 'https://teacher-sites.english-grammar-homework.com/' );

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
        $builder_code = eslflix_twa_generate_builder_code();
        $normalized_code = eslflix_teacher_websites_normalize_builder_code( $builder_code );

        update_user_meta( $user_id, ESLFLIX_TWA_CODE_HASH_META, wp_hash_password( $normalized_code ) );
        update_user_meta( $user_id, ESLFLIX_TWA_CODE_CREATED_META, current_time( 'mysql' ) );
        update_user_meta( $user_id, ESLFLIX_TWA_ACCESS_META, '0' );
        delete_user_meta( $user_id, ESLFLIX_TWA_CODE_USED_META );

        eslflix_twa_set_admin_notice(
            [
                'type'         => 'success',
                'message'      => sprintf( 'A new single-use builder code was generated for %s.', $user->display_name ),
                'secret_label' => 'Builder code',
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
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php eslflix_twa_render_action_fields( $user->ID, 'generate_code', $search ); ?>
                <button
                    type="submit"
                    class="button button-primary<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                    <?php if ( $replacement_code ) : ?>
                        data-confirm="<?php echo esc_attr( $has_access ? 'Generating a new code will lock this teacher out of the builder until they enter the replacement code. Continue?' : 'This replaces the builder code already waiting to be used. Continue?' ); ?>"
                    <?php endif; ?>
                >
                    <?php echo $replacement_code ? 'Generate new builder code' : 'Generate builder code'; ?>
                </button>
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
                                    <td data-label="Account actions">
                                        <div class="eslflix-twa-row-actions">
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                <?php eslflix_twa_render_action_fields( $teacher->ID, 'generate_code' ); ?>
                                                <button
                                                    type="submit"
                                                    class="button<?php echo $replacement_code ? ' eslflix-twa-requires-confirmation' : ''; ?>"
                                                    <?php if ( $replacement_code ) : ?>
                                                        data-confirm="<?php echo esc_attr( $has_access ? 'Generating a new code will lock this teacher out until the replacement code is entered. Continue?' : 'This replaces the builder code already waiting to be used. Continue?' ); ?>"
                                                    <?php endif; ?>
                                                ><?php echo $replacement_code ? 'New code' : 'Generate code'; ?></button>
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
