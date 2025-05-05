<?php
/**
 * Admin Page Setup for WP Static Exporter
 *
 * @package WP_Static_Exporter
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Adds the admin menu item under Tools.
 */
function wp_static_exporter_add_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'Static Site Export', 'wp-static-exporter' ),
		__( 'Static Site Export', 'wp-static-exporter' ),
		'manage_options',
		'wp-static-exporter',
		'wp_static_exporter_render_admin_page'
	);
}

/**
 * Renders the admin page content.
 */
function wp_static_exporter_render_admin_page() {
	wp_static_exporter_scan_and_sync_exports();
	?>
	<div class="wrap wp-static-exporter-wrap"> <!-- Added wrapper class -->
		<div class="exporter-title">
            <img src="<?php echo esc_url( WP_STATIC_EXPORTER_URL . 'admin/images/logo.png' ); ?>" alt="Plugin Logo" class="exporter-logo">
		    <h1><?php esc_html_e( 'Static Site Export', 'wp-static-exporter' ); ?></h1>
        </div>

		<p><?php esc_html_e( 'Generate a static HTML version of your site. Configure options below and click Generate.', 'wp-static-exporter' ); ?></p>

		<!-- Nonce fields for security -->
		<?php wp_nonce_field( 'wp_static_export_action', 'wp_static_export_nonce' ); ?>
        <?php wp_nonce_field( 'wp_static_export_delete_action', 'wp_static_export_delete_nonce' ); ?>
        <?php wp_nonce_field( 'wp_static_export_deploy_action', 'wp_static_export_deploy_nonce' ); ?>

        <!-- Options Area -->
        <div class="wp-static-exporter-options">

            <!-- Optimize Box -->
            <div class="wp-static-exporter-option-box">
                <h3><?php esc_html_e( 'Optimization', 'wp-static-exporter' ); ?></h3>
                <p>
                    <label>
                        <input type="checkbox" id="wp-static-exporter-optimize-output" name="optimize_output" value="1">
                        <?php esc_html_e( 'Optimize Output (Experimental)', 'wp-static-exporter' ); ?>
                    </label>
                    <small><?php esc_html_e( 'Combines CSS files and removes extra elements (e.g., SEO plugin comments/schema). May cause issues with complex themes/plugins.', 'wp-static-exporter' ); ?></small>
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="wp-static-exporter-use-fa-cdn" name="use_fa_cdn" value="1" checked="checked">
                        <?php esc_html_e( 'Use CDN for Font Awesome (Recommended)', 'wp-static-exporter' ); ?>
                    </label>
                    <small><?php esc_html_e( 'Replaces local Font Awesome with a CDN link for better reliability.', 'wp-static-exporter' ); ?></small>
                </p>
            </div>

            <!-- Forms Box -->
            <div class="wp-static-exporter-option-box">
                <h3><?php esc_html_e( 'Form Handling', 'wp-static-exporter' ); ?></h3>
                <p>
                    <label>
                        <input type="checkbox" id="wp-static-exporter-convert-forms" name="convert_forms" value="1">
                        <?php esc_html_e( 'Convert Forms to Simple PHP Mailer', 'wp-static-exporter' ); ?>
                    </label>
                    <small><?php esc_html_e( 'Requires PHP on the hosting server. Replaces forms with a basic PHP script.', 'wp-static-exporter' ); ?><strong> <?php esc_html_e('Note:', 'wp-static-exporter'); ?></strong> <?php esc_html_e('Email delivery depends heavily on server configuration and may be unreliable. Using a dedicated form endpoint service (e.g., Formspree, Getform) with the exported HTML is generally recommended for static sites.', 'wp-static-exporter'); ?></small>
                </p>
                <div class="form-recipient-options"> <!-- Wrapper for email input -->
                    <label for="wp-static-exporter-recipient-email">
                        <?php esc_html_e( 'Recipient Email (if converting forms):', 'wp-static-exporter' ); ?>
                    </label>
                    <input type="email" id="wp-static-exporter-recipient-email" name="recipient_email_override"
                           value="<?php echo esc_attr( get_option('wp_static_exporter_recipient_email_override', '') ); ?>"
                           placeholder="<?php echo esc_attr( get_option('admin_email') ); ?>"
                           size="40">
                    <small><?php esc_html_e( 'Defaults to site admin email if left blank.', 'wp-static-exporter' ); ?></small>
                </div>
            </div>


            <!-- URL Rewriting Box -->
            <div class="wp-static-exporter-option-box">
                <h3><?php esc_html_e( 'URL Rewriting', 'wp-static-exporter' ); ?></h3>
                <?php
                    // Get saved options or set defaults
                    $rewrite_mode = get_option('wp_static_exporter_url_rewrite_mode', 'relative');
                    $abs_scheme = get_option('wp_static_exporter_absolute_scheme', 'https');
                    $abs_url = get_option('wp_static_exporter_absolute_url', '');
                ?>
                <fieldset>
                    <legend class="screen-reader-text"><span><?php esc_html_e( 'URL Rewriting Mode', 'wp-static-exporter' ); ?></span></legend>
                    <p>
                        <label>
                            <input type="radio" name="url_rewrite_mode" value="relative" <?php checked( $rewrite_mode, 'relative' ); ?>>
                            <?php esc_html_e( 'Relative Paths (Default)', 'wp-static-exporter' ); ?>
                            <br><small><?php esc_html_e( 'Works anywhere, recommended for most cases and offline use.', 'wp-static-exporter' ); ?></small>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="url_rewrite_mode" value="absolute" <?php checked( $rewrite_mode, 'absolute' ); ?>>
                            <?php esc_html_e( 'Absolute URL', 'wp-static-exporter' ); ?>
                            <br><small><?php esc_html_e( 'Use if hosting the static site on a specific domain/path.', 'wp-static-exporter' ); ?></small>
                        </label>
                    </p>
                    <!-- Conditional Inputs for Absolute URL -->
                    <div id="wp-static-exporter-absolute-url-options" style="<?php echo ($rewrite_mode !== 'absolute' ? 'display: none;' : ''); ?> margin-left: 20px;">
                        <p>
                            <label for="wp-static-exporter-absolute-scheme"><?php esc_html_e( 'Scheme:', 'wp-static-exporter' ); ?></label>
                            <select id="wp-static-exporter-absolute-scheme" name="absolute_scheme">
                                <option value="https" <?php selected( $abs_scheme, 'https' ); ?>>https://</option>
                                <option value="http" <?php selected( $abs_scheme, 'http' ); ?>>http://</option>
                                <option value="protocol-relative" <?php selected( $abs_scheme, 'protocol-relative' ); ?>>// (Protocol Relative)</option>
                            </select>
                        </p>
                        <p>
                            <label for="wp-static-exporter-absolute-url"><?php esc_html_e( 'Base URL:', 'wp-static-exporter' ); ?></label>
                            <input type="text" id="wp-static-exporter-absolute-url" name="absolute_url" value="<?php echo esc_attr( $abs_url ); ?>" placeholder="www.your-static-site.com/optional-path" size="40">
                            <br><small><?php esc_html_e( 'Enter the domain and optional path where the static site will live (without http/https).', 'wp-static-exporter' ); ?></small>
                        </p>
                    </div>
                     <p> <!-- Moved Offline option here for clarity -->
                        <label>
                            <input type="radio" name="url_rewrite_mode" value="offline" <?php checked( $rewrite_mode, 'offline' ); ?>>
                            <?php esc_html_e( 'Offline Usage', 'wp-static-exporter' ); ?>
                            <br><small><?php esc_html_e( 'Same as Relative, ensures maximum compatibility for local viewing.', 'wp-static-exporter' ); ?></small>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" id="wp-static-exporter-generate-404" name="generate_404" value="1" <?php checked( get_option('wp_static_exporter_generate_404', 0), 1 ); ?>>
                            <?php esc_html_e( 'Generate 404 Page', 'wp-static-exporter' ); ?>
                        </label>
                        <small><?php esc_html_e( 'Includes a default 404.html in the export root.', 'wp-static-exporter' ); ?></small>
                    </p>
                </fieldset>
            </div>

        </div> <!-- .wp-static-exporter-options -->


		<!-- Button to trigger the export -->
		<p>
			<button type="button" id="wp-static-exporter-generate-btn" class="button button-primary button-hero"> <!-- Made button larger -->
				<?php esc_html_e( 'Generate Static Site Export', 'wp-static-exporter' ); ?>
			</button>
		</p>

        <!-- Status/Progress Area -->
		<div id="wp-static-exporter-status" style="border: 1px solid #ccc; padding: 10px; margin-top: 20px; max-height: 250px; background-color: #f9f9f9; overflow-y: auto;">
			<?php esc_html_e( 'Status updates will appear here...', 'wp-static-exporter' ); ?>
		</div>

		<!-- Download Link Area -->
		<div id="wp-static-exporter-download-link" style="margin-top: 20px;"></div>

        <!-- Deploy Link Area -->
        <div id="wp-static-exporter-deploy-link" style="margin-top: 10px;"></div>

        <hr> <!-- Separator -->

        <!-- Exports Table -->
		<h2><?php esc_html_e( 'Previous Exports', 'wp-static-exporter' ); ?></h2>
		<div id="wp-static-exporter-exports-list">
			<?php wp_static_exporter_display_exports_table(); ?>
		</div>

	</div> <!-- .wrap -->
	<?php
}

/**
 * Displays the table of previous exports.
 */
function wp_static_exporter_display_exports_table() {
	$exports = get_option( 'wp_static_exporter_exports_list', [] );
	if ( empty( $exports ) ) { echo '<p>' . esc_html__( 'No previous exports found.', 'wp-static-exporter' ) . '</p>'; return; }
	krsort( $exports );
	?>
	<table class="wp-list-table widefat fixed striped" id="wp-static-exporter-exports-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date Created', 'wp-static-exporter' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Filename', 'wp-static-exporter' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'wp-static-exporter' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $exports as $timestamp => $export_data ) : ?>
				<?php
					if ( ! is_array( $export_data ) || empty( $export_data['filename'] ) || empty( $export_data['url'] ) ) { continue; }
					$filename = $export_data['filename'];
					$download_url = $export_data['url'];
					$date_format = get_option( 'date_format' );
					$time_format = get_option( 'time_format' );
					$formatted_date = wp_date( "{$date_format} {$time_format}", $timestamp );
				?>
				<tr data-filename="<?php echo esc_attr( $filename ); ?>">
					<td><?php echo esc_html( $formatted_date ); ?></td>
					<td><?php echo esc_html( $filename ); ?></td>
					<td>
						<a href="<?php echo esc_url( $download_url ); ?>" class="button button-small" download>
							<?php esc_html_e( 'Download', 'wp-static-exporter' ); ?>
						</a>
						<button type="button" class="button button-small wp-static-exporter-delete-btn" data-filename="<?php echo esc_attr( $filename ); ?>">
							<?php esc_html_e( 'Delete', 'wp-static-exporter' ); ?>
						</button>
                        <button type="button" class="button button-small wp-static-exporter-deploy-btn" data-filename="<?php echo esc_attr( $filename ); ?>">
							<?php esc_html_e( 'Deploy to Test Folder', 'wp-static-exporter' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Scans the uploads directory for export files and syncs the stored list.
 */
function wp_static_exporter_scan_and_sync_exports() {
	$exports = get_option( 'wp_static_exporter_exports_list', [] );
	$upload_dir = wp_upload_dir();
	$exports_dir = $upload_dir['basedir'];
	$exports_url_base = $upload_dir['baseurl'];
	if ( ! is_dir( $exports_dir ) ) { return; }
	$files_found = glob( trailingslashit( $exports_dir ) . 'wp-static-export-*.zip' );
	$stored_filenames = wp_list_pluck( $exports, 'filename' );
	$list_updated = false;
	if ( ! empty( $files_found ) ) {
		foreach ( $files_found as $file_path ) {
			$filename = basename( $file_path );
			if ( ! in_array( $filename, $stored_filenames ) ) {
				$timestamp = 0;
				if ( preg_match( '/wp-static-export-(\d{8}-\d{6})\.zip$/', $filename, $matches ) ) {
					$date_str = $matches[1];
					$datetime = DateTime::createFromFormat( 'Ymd-His', $date_str );
					if ( $datetime ) { $timestamp = $datetime->getTimestamp(); }
				}
				if ( $timestamp === 0 ) { $timestamp = filemtime( $file_path ); }
				while ( isset( $exports[ $timestamp ] ) ) { $timestamp++; }
				$exports[ $timestamp ] = [ 'filename' => $filename, 'url' => trailingslashit( $exports_url_base ) . $filename, 'timestamp' => $timestamp, ];
				$list_updated = true;
			}
		}
	}
	if ( $list_updated ) { update_option( 'wp_static_exporter_exports_list', $exports ); }
}


/**
 * AJAX handler for deleting an export file.
 */
function wp_static_exporter_delete_ajax_handler() {
	check_ajax_referer( 'wp_static_export_delete_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( __( 'Permission denied.', 'wp-static-exporter' ), 403 ); }
	$filename_to_delete = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
	if ( empty( $filename_to_delete ) ) { wp_send_json_error( __( 'Error: No filename provided.', 'wp-static-exporter' ), 400 ); }
	$upload_dir = wp_upload_dir();
	$file_path = trailingslashit( $upload_dir['basedir'] ) . $filename_to_delete;
	if ( file_exists( $file_path ) ) {
		if ( unlink( $file_path ) ) {
			$exports = get_option( 'wp_static_exporter_exports_list', [] ); $timestamp_to_remove = null;
			foreach ($exports as $timestamp => $data) { if (isset($data['filename']) && $data['filename'] === $filename_to_delete) { $timestamp_to_remove = $timestamp; break; } }
			if ($timestamp_to_remove !== null) { unset($exports[$timestamp_to_remove]); update_option( 'wp_static_exporter_exports_list', $exports ); }
			wp_send_json_success( __( 'Export file deleted successfully.', 'wp-static-exporter' ) );
		} else { wp_send_json_error( __( 'Error: Could not delete the file. Check permissions.', 'wp-static-exporter' ), 500 ); }
	} else {
		$exports = get_option( 'wp_static_exporter_exports_list', [] ); $timestamp_to_remove = null;
		foreach ($exports as $timestamp => $data) { if (isset($data['filename']) && $data['filename'] === $filename_to_delete) { $timestamp_to_remove = $timestamp; break; } }
		if ($timestamp_to_remove !== null) { unset($exports[$timestamp_to_remove]); update_option( 'wp_static_exporter_exports_list', $exports ); }
        wp_send_json_success( __( 'Export removed from list (file not found).', 'wp-static-exporter' ) );
	}
}
add_action( 'wp_ajax_wp_static_export_delete', 'wp_static_exporter_delete_ajax_handler' );


/**
 * AJAX handler for triggering the static export.
 */
function wp_static_exporter_ajax_handler() {
	check_ajax_referer( 'wp_static_export_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( __( 'Permission denied.', 'wp-static-exporter' ), 403 ); }
    if ( file_exists( WP_STATIC_EXPORTER_PATH . 'includes/class-static-exporter.php' ) ) { require_once WP_STATIC_EXPORTER_PATH . 'includes/class-static-exporter.php'; }
    else { wp_send_json_error( __( 'Error: Core exporter class file not found.', 'wp-static-exporter' ), 500 ); }
	if ( ! class_exists( 'WP_Static_Exporter' ) ) { wp_send_json_error( __( 'Error: Exporter class WP_Static_Exporter not found after include attempt.', 'wp-static-exporter' ), 500 ); }
	@set_time_limit( 0 ); @ini_set( 'memory_limit', '512M' );
    $optimize_output = isset( $_POST['optimize_output'] ) && $_POST['optimize_output'] === '1';
    $use_fa_cdn = isset( $_POST['use_fa_cdn'] ) && $_POST['use_fa_cdn'] === '1';
    $convert_forms = isset( $_POST['convert_forms'] ) && $_POST['convert_forms'] === '1';
    $final_recipient_email = null;
    if ( $convert_forms ) {
        $admin_email = get_option('admin_email');
        $override_email = isset($_POST['recipient_email_override']) ? sanitize_email( wp_unslash( $_POST['recipient_email_override'] ) ) : '';
        update_option('wp_static_exporter_recipient_email_override', $override_email);
        if ( ! empty( $override_email ) && is_email( $override_email ) ) { $final_recipient_email = $override_email; }
        elseif ( ! empty( $admin_email ) && is_email( $admin_email ) ) { $final_recipient_email = $admin_email; }
        else { wp_send_json_error( [ 'message' => __( 'Error: Invalid or missing recipient email. Please set a valid admin email in WordPress settings or provide a valid override email.', 'wp-static-exporter'), 'progress' => [] ], 400 ); }
    }

    // Get URL rewriting options
    $url_rewrite_mode = isset($_POST['url_rewrite_mode']) ? sanitize_key($_POST['url_rewrite_mode']) : 'relative';
    $absolute_scheme = isset($_POST['absolute_scheme']) ? sanitize_key($_POST['absolute_scheme']) : 'https';
    $absolute_url = isset($_POST['absolute_url']) ? esc_url_raw( wp_unslash( $_POST['absolute_url'] ) ) : ''; // Use esc_url_raw for more flexibility but still sanitize

    // Basic validation for absolute URL
    if ($url_rewrite_mode === 'absolute' && empty($absolute_url)) {
         wp_send_json_error( [ 'message' => __( 'Error: Base URL cannot be empty when using Absolute URL mode.', 'wp-static-exporter'), 'progress' => [] ], 400 );
    }

    // Save URL options
    $generate_404 = isset($_POST['generate_404']) && $_POST['generate_404'] === '1';
    update_option('wp_static_exporter_generate_404', $generate_404 ? 1 : 0);
    update_option('wp_static_exporter_url_rewrite_mode', $url_rewrite_mode);
    update_option('wp_static_exporter_absolute_scheme', $absolute_scheme);
    update_option('wp_static_exporter_absolute_url', $absolute_url);


	$exporter = new WP_Static_Exporter();
	// Pass the final determined recipient email and URL options to the exporter
    $result = $exporter->run_export(
        $optimize_output,
        $use_fa_cdn,
        $convert_forms,
        $final_recipient_email,
        $url_rewrite_mode,
        $absolute_scheme,
        $absolute_url,
        $generate_404
    );

	if ( $result && is_array( $result ) && isset($result['url']) ) { // Expecting array with progress
		wp_send_json_success( [ 'message' => __( 'Export completed successfully!', 'wp-static-exporter' ), 'download_url' => $result['url'], 'progress' => isset($result['progress']) ? $result['progress'] : [], 'new_export' => $result ] );
	} else {
        $progress_messages = (isset($result['progress']) && is_array($result['progress'])) ? $result['progress'] : [__( 'Export failed. Check PHP error logs for more details.', 'wp-static-exporter' )];
		wp_send_json_error( [ 'message' => __( 'Export failed.', 'wp-static-exporter'), 'progress' => $progress_messages ], 500 );
	}
}
add_action( 'wp_ajax_wp_static_export_run', 'wp_static_exporter_ajax_handler' );


/**
 * Enqueue admin scripts and styles.
 */
function wp_static_exporter_enqueue_admin_scripts( $hook ) {
	if ( 'tools_page_wp-static-exporter' !== $hook ) { return; }
	// Enqueue JS
    wp_enqueue_script( 'wp-static-exporter-admin-js', WP_STATIC_EXPORTER_URL . 'admin/js/admin-script.js', [ 'jquery' ], WP_STATIC_EXPORTER_VERSION, true );
    // Enqueue CSS
    wp_enqueue_style( 'wp-static-exporter-admin-css', WP_STATIC_EXPORTER_URL . 'admin/css/admin-style.css', [], WP_STATIC_EXPORTER_VERSION );

	wp_localize_script( 'wp-static-exporter-admin-js', 'wpStaticExporter', [
		'ajax_url'       => admin_url( 'admin-ajax.php' ),
		'generate_nonce' => wp_create_nonce( 'wp_static_export_action' ),
        'delete_nonce'   => wp_create_nonce( 'wp_static_export_delete_action' ),
        'deploy_nonce'   => wp_create_nonce( 'wp_static_export_deploy_action' ),
        'dateFormat'     => get_option( 'date_format' ),
        'timeFormat'     => get_option( 'time_format' ),
		'text'           => [
			'generating'            => __( 'Generating export, please wait...', 'wp-static-exporter' ),
			'error'                 => __( 'An error occurred:', 'wp-static-exporter' ),
			'success'               => __( 'Export complete! Download link:', 'wp-static-exporter' ),
			'download'              => __( 'Download', 'wp-static-exporter' ),
            'delete'                => __( 'Delete', 'wp-static-exporter' ),
            'deleting'              => __( 'Deleting...', 'wp-static-exporter' ),
            'confirm_delete'        => __( 'Are you sure you want to delete this export file?', 'wp-static-exporter' ),
            'delete_success'        => __( 'Export deleted.', 'wp-static-exporter' ),
            'generateButtonDefault' => __( 'Generate Static Site Export', 'wp-static-exporter' ),
            'noExports'             => __( 'No previous exports found.', 'wp-static-exporter' ),
            'deploy'                => __( 'Deploy to Test Folder', 'wp-static-exporter' ),
            'deploying'             => __( 'Deploying...', 'wp-static-exporter' ),
            'prompt_deploy_folder'  => __( 'Enter a name for the deployment folder (e.g., my-static-site). It will be created in your WordPress installation directory (alongside wp-content).', 'wp-static-exporter' ),
            'invalid_folder_name'   => __( 'Invalid folder name. Please use only letters, numbers, hyphens, and underscores. No spaces or slashes.', 'wp-static-exporter' ),
            'deploy_success'        => __( 'Deployment successful! Site available at:', 'wp-static-exporter' )
		]
	] );
}


/**
 * AJAX handler for deploying an export file.
 */
function wp_static_exporter_deploy_ajax_handler() {
	check_ajax_referer( 'wp_static_export_deploy_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-static-exporter' ) ], 403 ); }
    if ( ! class_exists( 'ZipArchive' ) ) { wp_send_json_error( [ 'message' => __( 'Error: ZipArchive PHP extension is required but not enabled on the server.', 'wp-static-exporter' ) ], 500 ); }
	$zip_filename = isset( $_POST['zip_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['zip_filename'] ) ) : '';
    $deploy_folder_raw = isset( $_POST['deploy_folder'] ) ? wp_unslash( $_POST['deploy_folder'] ) : '';
    if ( empty( $zip_filename ) || empty( $deploy_folder_raw ) ) { wp_send_json_error( [ 'message' => __( 'Error: Missing filename or deployment folder name.', 'wp-static-exporter' ) ], 400 ); }
    $deploy_folder_sanitized = sanitize_file_name( $deploy_folder_raw );
    if ( empty($deploy_folder_sanitized) || $deploy_folder_sanitized !== $deploy_folder_raw ) { wp_send_json_error( [ 'message' => __( 'Error: Invalid deployment folder name provided. Use only letters, numbers, hyphens, underscores.', 'wp-static-exporter' ) ], 400 ); }
    $upload_dir = wp_upload_dir();
	$zip_file_path = trailingslashit( $upload_dir['basedir'] ) . $zip_filename;
    $base_deploy_dir = ABSPATH;
    $target_deploy_dir = trailingslashit( $base_deploy_dir ) . $deploy_folder_sanitized;
    if ( ! file_exists( $zip_file_path ) ) { error_log("WP Static Export ERROR: Source ZIP not found at $zip_file_path"); wp_send_json_error( [ 'message' => sprintf( __( 'Error: Source ZIP file not found: %s', 'wp-static-exporter' ), $zip_filename ) ], 404 ); }
    if ( file_exists( $target_deploy_dir ) ) { error_log("WP Static Export ERROR: Target deploy directory already exists: $target_deploy_dir"); wp_send_json_error( [ 'message' => sprintf( __( 'Error: Deployment folder "%s" already exists. Please choose a different name or delete the existing folder manually.', 'wp-static-exporter' ), $deploy_folder_sanitized ) ], 409 ); }
    if ( ! file_exists( $base_deploy_dir ) ) { if ( ! wp_mkdir_p( $base_deploy_dir ) ) { error_log("WP Static Export ERROR: Failed to create base deploy directory: $base_deploy_dir"); wp_send_json_error( [ 'message' => sprintf( __( 'Error: Could not create base deployment directory: %s. Check permissions.', 'wp-static-exporter' ), basename($base_deploy_dir) ) ], 500 ); } }
    if ( ! wp_mkdir_p( $target_deploy_dir ) ) { error_log("WP Static Export ERROR: Failed to create target deploy directory: $target_deploy_dir"); wp_send_json_error( [ 'message' => sprintf( __( 'Error: Could not create target deployment directory: %s. Check permissions.', 'wp-static-exporter' ), $deploy_folder_sanitized ) ], 500 ); }
    $zip = new ZipArchive(); $res = $zip->open( $zip_file_path );
    if ( $res === true ) {
        if ( $zip->extractTo( $target_deploy_dir ) ) {
            $zip->close();
            $deploy_url = trailingslashit( site_url( $deploy_folder_sanitized ) );
             wp_send_json_success( [ 'message' => sprintf( __( 'Successfully deployed %s to %s.', 'wp-static-exporter' ), $zip_filename, $deploy_folder_sanitized ), 'deploy_url' => $deploy_url ] );
        } else {
            $zip->close(); if (is_dir($target_deploy_dir)) { @rmdir($target_deploy_dir); }
             error_log("WP Static Export ERROR: Failed to extract ZIP contents to $target_deploy_dir");
             wp_send_json_error( [ 'message' => __( 'Error: Could not extract ZIP file contents. Check permissions or ZIP file integrity.', 'wp-static-exporter' ) ], 500 );
        }
    } else { error_log("WP Static Export ERROR: Failed to open ZIP file $zip_file_path. ZipArchive error code: $res"); wp_send_json_error( [ 'message' => sprintf(__( 'Error: Could not open ZIP file. Error code: %s', 'wp-static-exporter' ), $res) ], 500 ); }
}
add_action( 'wp_ajax_wp_static_export_deploy', 'wp_static_exporter_deploy_ajax_handler' );

?>
