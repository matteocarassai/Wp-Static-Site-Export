<?php
/**
 * Core Static Exporter Class
 *
 * @package WP_Static_Exporter
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class WP_Static_Exporter {

	// Basic properties
    private $temp_base_dir = '';
	private $temp_export_dir = '';
	private $zip_file_path = '';
    private $zip_file_url = '';
	private $urls_to_process = [];
	private $assets_to_download = [];
    private $css_urls_to_combine = [];
    private $processed_urls = [];
    private $downloaded_assets = [];
    private $site_url = '';
    private $site_path = '';
    private $progress_messages = [];

    // URL rewriting properties (version 1.6 defaults)
    private $url_rewrite_mode = 'relative'; // only 'relative' and 'offline' are supported in 1.6
    private $absolute_scheme = 'https';
    private $absolute_url_base = ''; // Not used in version 1.6
    private $generate_404 = false;

    // Define Font Awesome CDN URL
    const FONT_AWESOME_CDN_URL = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir = wp_upload_dir();
		$this->temp_base_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-static-exports-temp';
        $this->site_url = trailingslashit( get_site_url() );
        $this->site_path = trailingslashit( ABSPATH );
	}

    /**
     * Logs a message.
     */
    private function log_status( $message ) {
        $log_entry = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log("WP Static Export: " . $message);
        $this->progress_messages[] = esc_html($log_entry);
    }

	/**
	 * Main method to run the export process.
	 *
	 * @param bool $optimize_output Whether to enable optimization (combine CSS, clean HTML).
     * @param bool $use_fa_cdn Whether to use Font Awesome CDN.
     * @param bool $convert_forms Whether to convert forms to PHP mailer.
     * @param string|null $final_recipient_email The validated email address for mailer config.
     * @param string $url_rewrite_mode The selected URL rewriting mode ('relative' or 'offline').
     * @param string $absolute_scheme The scheme for absolute URLs (not used in version 1.6).
     * @param string $absolute_url The base URL for absolute paths (not used in version 1.6).
	 * @return array|false Array with 'url', 'filename', 'timestamp', and 'progress' on success, false on failure.
	 */
    public function run_export( $optimize_output = false, $use_fa_cdn = true, $convert_forms = false, $final_recipient_email = null, $url_rewrite_mode = 'relative', $absolute_scheme = 'https', $absolute_url = '', $generate_404 = false ) {
        $this->generate_404 = (bool) $generate_404;
		$this->progress_messages = [];
        // Set URL rewriting mode; in version 1.6 we only support relative/offline (both treated the same)
        $this->url_rewrite_mode = ($url_rewrite_mode === 'absolute') ? 'relative' : 'relative';

		$this->log_status( __( 'Starting export process...', 'wp-static-exporter' ) );
        $this->log_status( sprintf( __( 'Optimize Output mode: %s', 'wp-static-exporter' ), $optimize_output ? 'Enabled' : 'Disabled' ) );
        $this->log_status( sprintf( __( 'Use Font Awesome CDN: %s', 'wp-static-exporter' ), $use_fa_cdn ? 'Enabled' : 'Disabled' ) );
        $this->log_status( sprintf( __( 'Convert Forms to PHP Mailer: %s', 'wp-static-exporter' ), $convert_forms ? 'Enabled' : 'Disabled' ) );
        if ( $convert_forms && $final_recipient_email ) {
             $this->log_status( sprintf( __( 'Form Recipient Email: %s', 'wp-static-exporter' ), $final_recipient_email ) );
        }
        $this->log_status( sprintf( __( 'URL Rewriting Mode: %s', 'wp-static-exporter' ), $this->url_rewrite_mode ) );

        $this->assets_to_download = [];
        $this->css_urls_to_combine = [];
        $this->processed_urls = [];
        $this->downloaded_assets = [];

		if ( ! $this->prepare_directories() ) {
            $this->log_status( __( 'Error: Could not prepare temporary directories.', 'wp-static-exporter' ) );
			return ['progress' => $this->progress_messages];
		}

		$this->discover_urls();

		if ( empty( $this->urls_to_process ) ) {
            $this->log_status( __( 'Error: No URLs found to process.', 'wp-static-exporter' ) );
			$this->cleanup();
			return ['progress' => $this->progress_messages];
		}

        $this->log_status( sprintf( __( 'Found %d URLs to process.', 'wp-static-exporter' ), count( $this->urls_to_process ) ) );

		foreach ( $this->urls_to_process as $url ) {
			$this->process_url( $url, $optimize_output, $use_fa_cdn, $convert_forms );
		}

        $this->log_status( sprintf( __( 'Found %d unique non-CSS assets to download initially.', 'wp-static-exporter' ), count( $this->assets_to_download ) ) );
        $this->log_status( sprintf( __( 'Found %d unique CSS assets to process/combine.', 'wp-static-exporter' ), count( $this->css_urls_to_combine ) ) );

        $this->download_assets( $optimize_output, $use_fa_cdn );

        $this->log_status( sprintf( __( 'Total unique assets to download (including from CSS): %d', 'wp-static-exporter' ), count( $this->assets_to_download ) ) );

        $this->download_discovered_assets( $use_fa_cdn );

        if ( $this->generate_404 ) {
            $this->log_status( __( 'Generating default 404.html page...', 'wp-static-exporter' ) );
            $error_page_html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p>The requested page could not be found.</p></body></html>';
            file_put_contents( $this->temp_export_dir . '/404.html', $error_page_html );
        }

		if ( ! $this->create_zip( $convert_forms, $final_recipient_email ) ) {
            $this->log_status( __( 'Error: Failed to create ZIP archive.', 'wp-static-exporter' ) );
			$this->cleanup();
			return ['progress' => $this->progress_messages];
		}

        $this->log_status( __( 'Export complete. Cleaning up temporary files...', 'wp-static-exporter' ) );
		$this->cleanup( false );

        $this->log_status( sprintf( __( 'Success! Export available at: %s', 'wp-static-exporter' ), $this->zip_file_url ) );

        $export_details = [
            'filename'  => basename( $this->zip_file_path ),
            'url'       => $this->zip_file_url,
            'timestamp' => time(),
            'progress'  => $this->progress_messages
        ];
        $this->store_export_details( $export_details );
		return $export_details;
	}

    /**
	 * Stores the export details.
	 */
	private function store_export_details( $export_details ) {
		$exports = get_option( 'wp_static_exporter_exports_list', [] );
		$timestamp_key = $export_details['timestamp'];
		while (isset($exports[$timestamp_key])) { $timestamp_key++; }
		$exports[ $timestamp_key ] = $export_details;
		update_option( 'wp_static_exporter_exports_list', $exports );
	}

	/**
	 * Generates a default 404.html page in the export root.
	 * (Not available in version 1.6; this function is omitted.)
	 */

	/**
	 * Creates temporary directories.
	 */
	private function prepare_directories() {
        if ( ! file_exists( $this->temp_base_dir ) && ! wp_mkdir_p( $this->temp_base_dir ) ) { return false; }
        $export_id = uniqid( 'export_' );
        $this->temp_export_dir = trailingslashit( $this->temp_base_dir ) . $export_id;
        if ( ! wp_mkdir_p( $this->temp_export_dir ) ) { return false; }
        $upload_dir = wp_upload_dir();
        $this->zip_file_path = trailingslashit( $upload_dir['basedir'] ) . 'wp-static-export-' . date('Ymd-His') . '.zip';
        $this->zip_file_url = trailingslashit( $upload_dir['baseurl'] ) . basename($this->zip_file_path);
        $this->log_status( sprintf(__( 'Temporary export directory created: %s', 'wp-static-exporter'), $this->temp_export_dir) );
        return true;
	}

	/**
	 * Discovers URLs.
	 */
	private function discover_urls() {
        $this->log_status( __( 'Discovering URLs...', 'wp-static-exporter' ) );
		$this->urls_to_process = []; $this->processed_urls = [];
		$this->urls_to_process[] = $this->site_url;
		$posts = get_posts( ['post_type'=>'post','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids'] );
		foreach ( $posts as $post_id ) { $this->urls_to_process[] = get_permalink( $post_id ); }
		$pages = get_pages( ['post_type'=>'page','post_status'=>'publish','number'=>-1,'fields'=>'ids'] );
		foreach ( $pages as $page_id ) { $this->urls_to_process[] = get_permalink( $page_id ); }
        $this->urls_to_process = array_unique( $this->urls_to_process );
	}

	/**
	 * Processes a single URL.
	 */
	private function process_url( $url, $optimize_output, $use_fa_cdn, $convert_forms ) {
        if ( in_array( $url, $this->processed_urls ) ) { return; }
        $this->processed_urls[] = $url;
        $this->log_status( sprintf( __( 'Processing URL: %s', 'wp-static-exporter' ), $url ) );

        // --- Fetch HTML ---
        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) { $this->log_status( sprintf( __( 'Error fetching %s: %s', 'wp-static-exporter' ), $url, is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response ) ) ); return; }
        $html_content = wp_remote_retrieve_body( $response );
        if ( empty( $html_content ) ) { $this->log_status( sprintf( __( 'Warning: Empty content received for %s', 'wp-static-exporter' ), $url ) ); return; }

        // --- Clean potential prefix text before parsing ---
        $doctype_pos = stripos($html_content, '<!DOCTYPE');
        if ($doctype_pos === false) { $doctype_pos = stripos($html_content, '<html'); }
        if ($doctype_pos !== false && $doctype_pos > 0) {
            $prefix_text = substr($html_content, 0, $doctype_pos);
            if(trim($prefix_text) !== '') { $this->log_status( sprintf('Warning: Found and removed potential prefix text before DOCTYPE/HTML tag on %s. Prefix: %s...', $url, substr($prefix_text, 0, 100)) ); }
            $html_content = substr($html_content, $doctype_pos);
        }

        // --- Prepare Save Path ---
        $relative_url = str_replace( $this->site_url, '', $url );
        $save_path_dir = $this->temp_export_dir . '/' . $relative_url;
        if ( empty( $relative_url ) || $relative_url === '/' ) { $save_path_dir = $this->temp_export_dir; $save_path = trailingslashit( $save_path_dir ) . 'index.html'; }
        else { $save_path_dir = rtrim($save_path_dir, '/') . '/'; $save_path = $save_path_dir . 'index.html'; }
        if ( ! file_exists( $save_path_dir ) && ! wp_mkdir_p( $save_path_dir ) ) { $this->log_status( sprintf( __( 'Error: Could not create directory %s', 'wp-static-exporter' ), $save_path_dir ) ); return; }

        // --- Parse HTML ---
        $this->log_status( sprintf( __( 'Parsing HTML for %s', 'wp-static-exporter' ), $url ) );
        $dom = new DOMDocument(); libxml_use_internal_errors( true );
        if ( ! $dom->loadHTML( mb_convert_encoding( $html_content, 'HTML-ENTITIES', 'UTF-8' ) ) ) {
             $this->log_status( sprintf( __( 'Warning: Could not parse HTML for %s. Skipping modification.', 'wp-static-exporter' ), $url ) );
             libxml_clear_errors(); libxml_use_internal_errors( false );
             if ( file_put_contents( $save_path, $html_content ) === false ) { $this->log_status( sprintf( __( 'Error: Could not save original HTML file %s', 'wp-static-exporter' ), $save_path ) ); }
             return;
        }
        libxml_clear_errors(); libxml_use_internal_errors( false );
        $xpath = new DOMXPath( $dom );

        // --- Remove Dynamic & Unwanted Elements ---
        $this->log_status( sprintf( __( 'Removing dynamic/unwanted elements for %s', 'wp-static-exporter' ), $url ) );
        $elements_to_remove_queries = [ '//*[@id="comments"]', '//*[contains(@class, "comments-area")]', '//*[contains(@class, "comment-respond")]', '//*[contains(@class, "search-form")]', '//*[@role="search"]', '//*[@id="wpadminbar"]', '//script[contains(text(), "wp-emoji-release.min.js")]', '//link[@rel="https://api.w.org/"]', '//link[@rel="EditURI"]', '//link[@rel="wlwmanifest"]', '//link[@rel="shortlink"]', '//meta[@name="generator"]', '//base' ];
        if ( ! $convert_forms ) { $elements_to_remove_queries[] = '//form'; $elements_to_remove_queries[] = '//input[@type="submit"]'; }
        if ( $optimize_output ) {
             $this->log_status( 'Applying enhanced cleaning rules...' );
             $optimization_queries = [ '//comment()[contains(., "Yoast") or contains(., "Rank Math")]', '//script[@type="application/ld+json"]', '//div[contains(@class, "wpcf7")]', '//form[contains(@class, "wpcf7-form")]', '//div[contains(@class, "sharedaddy")]', '//div[@class="sd-content"]', '//div[starts-with(@class, "jetpack-")]', '//div[@id="jp-relatedposts"]', '//div[@class="addtoany_share_save_container"]', '//div[@class="a2a_kit"]', '//div[@class="heateor_sss_sharing_container"]', '//div[@class="heateor_sss_horizontal_sharing"]', '//div[@class="yarpp-related"]', '//div[@class="wpulike"]', '//div[@class="wp-block-jetpack-related-posts"]', '//script[contains(@src, "disqus.com")]', '//div[@id="disqus_thread"]', '//div[@id="google_translate_element"]' ];
             if ($convert_forms) { $optimization_queries[] = '//script[contains(@src, "fluentform")]'; $optimization_queries[] = '//script[contains(., "fluent_form_")]'; $optimization_queries[] = '//style[contains(., "fluentform")]'; }
             $elements_to_remove_queries = array_merge($elements_to_remove_queries, $optimization_queries);
        }
        if ( $use_fa_cdn ) { $elements_to_remove_queries[] = '//link[contains(@href, "fontawesome")]'; $elements_to_remove_queries[] = '//link[contains(@href, "font-awesome")]'; $elements_to_remove_queries[] = '//link[contains(@href, "all.css")]'; $elements_to_remove_queries[] = '//link[contains(@href, "all.min.css")]'; }
        foreach ( $elements_to_remove_queries as $query ) {
            $nodes = $xpath->query( $query );
            for ( $i = $nodes->length - 1; $i >= 0; $i-- ) { $node = $nodes->item( $i ); if ($node && $node->parentNode) { $node->parentNode->removeChild( $node ); } }
        }

        // --- Inject Font Awesome CDN Link if needed ---
        if ( $use_fa_cdn ) {
            $head = $xpath->query('//head')->item(0);
            $fa_cdn_exists = $xpath->query('//link[contains(@href, "fontawesome.com") or contains(@href, "cloudflare.com/ajax/libs/font-awesome")]')->length > 0;
            if ($head && !$fa_cdn_exists) { $this->log_status('Injecting Font Awesome CDN link.'); $fa_link = $dom->createElement('link'); $fa_link->setAttribute('rel', 'stylesheet'); $fa_link->setAttribute('href', self::FONT_AWESOME_CDN_URL); $head->appendChild($fa_link); }
        }

        // --- Discover Assets & Rewrite Links ---
        $this->log_status( sprintf( __( 'Discovering assets & rewriting links for %s (Mode: %s)', 'wp-static-exporter' ), $url, $this->url_rewrite_mode ) );
        $asset_queries = [ '//link[@rel="stylesheet"]' => 'href', '//script' => 'src', '//img' => 'src', '//source' => 'src', '//video' => 'poster' ];
        $internal_css_links_found_on_page = [];
        foreach ( $asset_queries as $query => $attribute ) {
            $nodes = $xpath->query( $query );
            foreach ( $nodes as $node ) {
                if ( $node->hasAttribute( $attribute ) ) {
                    $asset_url = $node->getAttribute( $attribute );
                    if ( ! empty( $asset_url ) ) {
                        $absolute_asset_url = $this->make_absolute_url( $asset_url, $url );
                        if ( $absolute_asset_url && strpos( $absolute_asset_url, $this->site_url ) === 0 ) {
                            if ( $use_fa_cdn && (strpos($absolute_asset_url, 'fontawesome') !== false || strpos($absolute_asset_url, 'font-awesome') !== false) ) { $this->log_status( sprintf('Skipping local Font Awesome CSS due to CDN option: %s', $absolute_asset_url) ); continue; }
                            if ( $convert_forms && strpos($absolute_asset_url, 'fluentform') !== false ) { $this->log_status( sprintf('Skipping Fluent Forms JS due to form conversion: %s', $absolute_asset_url) ); continue; }

                            $is_css = ($node->tagName === 'link' && $node->getAttribute('rel') === 'stylesheet');
                            if ($is_css) { if ( ! in_array( $absolute_asset_url, $this->css_urls_to_combine ) ) { $this->css_urls_to_combine[] = $absolute_asset_url; } $internal_css_links_found_on_page[] = $node; }
                            else { if ( ! in_array( $absolute_asset_url, $this->assets_to_download ) ) { $this->assets_to_download[] = $absolute_asset_url; } }

                            // --- URL Rewriting Logic ---
                            $new_asset_path = '';
                            $asset_relative_url = str_replace( $this->site_url, '', $absolute_asset_url );
                            $asset_save_path = $this->temp_export_dir . '/assets/' . ltrim($asset_relative_url, '/');

                            // In version 1.6, URL rewriting always uses relative paths.
                            $new_asset_path = $this->get_relative_path( $save_path, $asset_save_path );
                            // --- End URL Rewriting Logic ---
                            $node->setAttribute( $attribute, $new_asset_path );
                        }
                    }
                }
            }
        }

        // If combining CSS, remove original internal <link> tags and add a single one (using appropriate rewrite mode)
        if ( $optimize_output && !empty($internal_css_links_found_on_page) ) {
            $this->log_status( 'Combining CSS: Removing original links and adding combined link.' );
            $head = $xpath->query('//head')->item(0);
            $existing_combined_link = $xpath->query('//link[@id="wp-static-exporter-combined-styles"]')->length > 0;
            foreach ($internal_css_links_found_on_page as $node_to_remove) { if ($node_to_remove && $node_to_remove->parentNode) { $node_to_remove->parentNode->removeChild($node_to_remove); } }
            if ($head && !$existing_combined_link) {
                $combined_css_save_path = $this->temp_export_dir . '/assets/combined-styles.css';
                $relative_combined_path = $this->get_relative_path( $save_path, $combined_css_save_path );
                $new_link = $dom->createElement('link'); 
                $new_link->setAttribute('rel', 'stylesheet'); 
                $new_link->setAttribute('id', 'wp-static-exporter-combined-styles'); 
                $new_link->setAttribute('href', $relative_combined_path); 
                $head->appendChild($new_link); 
                $this->log_status( 'Added combined CSS link tag.' );
            }
        }

        // --- Convert Forms if enabled ---
        if ( $convert_forms ) {
            $this->log_status( sprintf( 'Converting forms on %s to use PHP mailer.', $url ) );
            $forms = $xpath->query('//form');
            $mailer_script_path_abs = $this->temp_export_dir . '/php-mailer/sendmail.php';
            $mailer_script_path_rel = $this->get_relative_path($save_path, $mailer_script_path_abs);
            foreach ($forms as $form) {
                $form->setAttribute('action', $mailer_script_path_rel); 
                $form->setAttribute('method', 'POST');
                $this->log_status('Updated form action and method.');
                $hidden_inputs_to_remove = $xpath->query('.//input[@type="hidden"][starts-with(@name, "_wpcf7") or starts-with(@name, "_wpnonce") or starts-with(@name, "_fluentform_")]', $form);
                $this->log_status( sprintf('Found %d hidden fields to remove from form.', $hidden_inputs_to_remove->length) );
                foreach ($hidden_inputs_to_remove as $input) { 
                    $input_name = $input->getAttribute('name'); 
                    if ($input && $input->parentNode) { 
                        $input->parentNode->removeChild($input); 
                        $this->log_status( sprintf('Removed hidden input: %s', $input_name) ); 
                    } 
                }
                $location_input = $dom->createElement('input'); 
                $location_input->setAttribute('type', 'hidden'); 
                $location_input->setAttribute('name', '_form_location'); 
                $location_input->setAttribute('value', $url);
                $form->appendChild($location_input);
            }
        }

        // --- Rewrite Internal Links (<a> tags) ---
        $this->log_status( sprintf( __( 'Rewriting internal links for %s (Mode: %s)', 'wp-static-exporter' ), $url, $this->url_rewrite_mode ) );
        $links = $xpath->query( '//a' );
        foreach ( $links as $link ) {
            if ( $link->hasAttribute( 'href' ) ) {
                $href = $link->getAttribute( 'href' ); 
                $absolute_href = $this->make_absolute_url( $href, $url ); 
                $processed_urls_slashed = array_map('trailingslashit', $this->urls_to_process);
                if ( $absolute_href && in_array( trailingslashit($absolute_href), $processed_urls_slashed ) ) {
                    $target_relative_url = str_replace( $this->site_url, '', trailingslashit($absolute_href) );
                    $new_link_path = '';
                    // In version 1.6, always use relative URL rewriting.
                    $target_save_path = $this->temp_export_dir . '/' . $target_relative_url . 'index.html';
                    if ( empty($target_relative_url) || $target_relative_url === '/' ) { 
                        $target_save_path = $this->temp_export_dir . '/index.html'; 
                    }
                    $new_link_path = $this->get_relative_path( $save_path, $target_save_path );
                    $link->setAttribute( 'href', $new_link_path );
                }
                elseif ( $absolute_href && strpos( $absolute_href, $this->site_url ) === 0 ) { 
                    $link->setAttribute( 'href', '#' ); 
                    $this->log_status( sprintf( __( 'Warning: Rewriting internal link %s to # as target is not exported.', 'wp-static-exporter' ), $href ) ); 
                }
            }
        }

        // --- Get Modified HTML ---
        $modified_html_content = $dom->saveHTML();

        // --- Save Modified HTML ---
        if ( file_put_contents( $save_path, $modified_html_content ) === false ) { 
            $this->log_status( sprintf( __( 'Error: Could not save HTML file %s', 'wp-static-exporter' ), $save_path ) ); 
        }
        else { 
            $this->log_status( sprintf( __( 'Saved HTML to: %s', 'wp-static-exporter' ), $save_path ) ); 
        }
	}

	/**
	 * Downloads assets, combines CSS, parses CSS for assets.
	 */
	private function download_assets( $optimize_output, $use_fa_cdn ) {
		$this->log_status( sprintf( __( 'Processing %d CSS files...', 'wp-static-exporter' ), count( $this->css_urls_to_combine ) ) );
		$this->downloaded_assets = []; 
        $css_content_to_combine = ''; 
        $processed_css_content = '';
		foreach ( $this->css_urls_to_combine as $asset_url ) {
             if ( in_array( $asset_url, $this->downloaded_assets ) ) continue;
             if ( $use_fa_cdn && (strpos($asset_url, 'fontawesome') !== false || strpos($asset_url, 'font-awesome') !== false) ) { 
                 $this->log_status( sprintf('Skipping CSS processing/combining for Font Awesome URL: %s', $asset_url) ); 
                 continue; 
             }
             $this->log_status( sprintf( __( 'Fetching CSS: %s', 'wp-static-exporter' ), $asset_url ) );
             $response = wp_remote_get( $asset_url, [ 'timeout' => 30 ] );
             if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                 $css_content = wp_remote_retrieve_body( $response );
                 $asset_relative_url = str_replace( $this->site_url, '', $asset_url );
                 $asset_relative_url_path = parse_url( $asset_relative_url, PHP_URL_PATH );
                 if (!$asset_relative_url_path) { 
                     $this->log_status( sprintf( __( 'Warning: Could not parse path for CSS URL: %s. Skipping.', 'wp-static-exporter' ), $asset_url ) ); 
                     continue; 
                 }
                 $css_save_path = $this->temp_export_dir . '/assets/' . ltrim( $asset_relative_url_path, '/' );
                 $processed_css_content = $this->process_css_content( $css_content, $asset_url, $css_save_path, $use_fa_cdn );
                 if ( $optimize_output ) { 
                     $css_content_to_combine .= "/* Source: " . esc_url($asset_url) . " */\n" . $processed_css_content . "\n\n"; 
                     $this->downloaded_assets[] = $asset_url; 
                 }
                 else { 
                     $asset_save_dir = dirname( $css_save_path ); 
                     if ( ! file_exists( $asset_save_dir ) ) { 
                         wp_mkdir_p( $asset_save_dir ); 
                     } 
                     if ( file_put_contents( $css_save_path, $processed_css_content ) === false ) { 
                         $this->log_status( sprintf( __( 'Error saving processed CSS %s to %s.', 'wp-static-exporter' ), $asset_url, $css_save_path ) ); 
                     } else { 
                         $this->log_status( sprintf( __( 'Saved processed CSS to: %s', 'wp-static-exporter' ), $css_save_path ) ); 
                         $this->downloaded_assets[] = $asset_url; 
                     } 
                 }
             } else { 
                 $this->log_status( sprintf( __( 'Error fetching CSS %s: %s', 'wp-static-exporter' ), $asset_url, is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response ) ) ); 
             }
        }
        if ( $optimize_output && ! empty( $css_content_to_combine ) ) {
            $combined_css_path = $this->temp_export_dir . '/assets/combined-styles.css';
            if ( ! file_exists( dirname( $combined_css_path ) ) ) { 
                wp_mkdir_p( dirname( $combined_css_path ) ); 
            }
            $this->log_status( sprintf( __( 'Saving combined CSS to: %s', 'wp-static-exporter' ), $combined_css_path ) );
            if ( file_put_contents( $combined_css_path, $css_content_to_combine ) === false ) { 
                $this->log_status( sprintf( __( 'Error saving combined CSS to %s.', 'wp-static-exporter' ), $combined_css_path ) ); 
            }
        }
        $this->log_status( sprintf( __( 'Finished processing CSS. Processed %d files.', 'wp-static-exporter' ), count( $this->downloaded_assets ) ) );
	}

    /**
     * Downloads assets discovered within CSS or other non-CSS assets.
     */
    private function download_discovered_assets( $use_fa_cdn ) {
        $this->log_status( sprintf( __( 'Attempting to download %d discovered assets (non-CSS and from CSS)...', 'wp-static-exporter' ), count( $this->assets_to_download ) ) );
        $newly_downloaded_count = 0; 
        $assets_to_process_pass = $this->assets_to_download; 
        $processed_in_this_pass = [];
        while (!empty($assets_to_process_pass)) {
            $current_asset_url = array_shift($assets_to_process_pass);
            if ( in_array( $current_asset_url, $this->downloaded_assets ) || in_array($current_asset_url, $processed_in_this_pass) ) { 
                continue; 
            }
            $processed_in_this_pass[] = $current_asset_url;
            if ( $use_fa_cdn && (strpos($current_asset_url, '/webfonts/') !== false || strpos($current_asset_url, '/fontawesome/') !== false) ) { 
                $path_info = pathinfo(parse_url($current_asset_url, PHP_URL_PATH)); 
                if (isset($path_info['extension']) && in_array($path_info['extension'], ['woff', 'woff2', 'ttf', 'eot', 'svg'])) { 
                    $this->log_status( sprintf('Skipping Font Awesome font download due to CDN option: %s', $current_asset_url) ); 
                    continue; 
                } 
            }
            $asset_relative_url = str_replace( $this->site_url, '', $current_asset_url );
            $asset_relative_url_path = parse_url( $asset_relative_url, PHP_URL_PATH );
            if (!$asset_relative_url_path) { 
                $this->log_status( sprintf( __( 'Warning: Could not parse path for asset URL: %s. Skipping.', 'wp-static-exporter' ), $current_asset_url ) ); 
                continue; 
            }
            $asset_save_path = $this->temp_export_dir . '/assets/' . ltrim( $asset_relative_url_path, '/' );
            $asset_save_dir = dirname( $asset_save_path );
            if ( ! file_exists( $asset_save_dir ) && ! wp_mkdir_p( $asset_save_dir ) ) { 
                $this->log_status( sprintf( __( 'Error: Could not create asset directory %s. Skipping asset %s.', 'wp-static-exporter' ), $asset_save_dir, $current_asset_url ) ); 
                continue; 
            }
            $this->log_status( sprintf( __( 'Downloading asset: %s', 'wp-static-exporter' ), $current_asset_url ) );
            $response = wp_remote_get( $current_asset_url, [ 'timeout' => 30 ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) { 
                $this->log_status( sprintf( __( 'Error downloading %s: %s', 'wp-static-exporter' ), $current_asset_url, is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response ) ) ); 
                continue; 
            }
            $asset_content = wp_remote_retrieve_body( $response );
            if ( file_put_contents( $asset_save_path, $asset_content ) === false ) { 
                $this->log_status( sprintf( __( 'Error saving asset %s to %s.', 'wp-static-exporter' ), $current_asset_url, $asset_save_path ) ); 
            }
            else { 
                $this->log_status( sprintf( __( 'Saved asset to: %s', 'wp-static-exporter' ), $asset_save_path ) ); 
                $this->downloaded_assets[] = $current_asset_url; 
                $newly_downloaded_count++; 
            }
        }
         $this->log_status( sprintf( __( 'Finished downloading discovered assets. Downloaded %d new assets in this pass.', 'wp-static-exporter' ), $newly_downloaded_count ) );
    }

    /**
     * Parses CSS content, queues assets, and rewrites paths based on selected mode.
     */
    private function process_css_content( $css_content, $original_css_url, $css_save_path, $use_fa_cdn ) {
        $this->log_status( sprintf( __( 'Processing CSS content from: %s (Mode: %s)', 'wp-static-exporter' ), $original_css_url, $this->url_rewrite_mode ) );
        preg_match_all( '/url\((?:\'|")?([^\'")]+)(?:\'|")?\)/i', $css_content, $matches );
        if ( ! empty( $matches[1] ) ) {
            $found_urls = array_unique( $matches[1] );
            $this->log_status( sprintf( __( 'Found %d potential url() paths in CSS.', 'wp-static-exporter' ), count( $found_urls ) ) );
            foreach ( $found_urls as $original_path ) {
                $path_in_css = trim( $original_path );
                if ( strpos( $path_in_css, 'data:' ) === 0 || preg_match( '/^(?:[a-z]+:)?\/\//i', $path_in_css ) ) { 
                    continue; 
                }
                $absolute_asset_url = $this->make_absolute_url( $path_in_css, $original_css_url );
                if ( $absolute_asset_url && strpos( $absolute_asset_url, $this->site_url ) === 0 ) {
                    $is_fa_font = false;
                    if ( $use_fa_cdn && (strpos($absolute_asset_url, '/webfonts/') !== false || strpos($absolute_asset_url, '/fontawesome/') !== false) ) { 
                        $path_info = pathinfo(parse_url($absolute_asset_url, PHP_URL_PATH)); 
                        if (isset($path_info['extension']) && in_array($path_info['extension'], ['woff', 'woff2', 'ttf', 'eot', 'svg'])) { 
                            $is_fa_font = true; 
                        } 
                    }
                    if (!$is_fa_font) { 
                        if ( ! in_array( $absolute_asset_url, $this->assets_to_download ) && ! in_array( $absolute_asset_url, $this->downloaded_assets ) ) { 
                            $this->log_status( sprintf( __( 'Queueing asset found in CSS: %s', 'wp-static-exporter' ), $absolute_asset_url ) ); 
                            $this->assets_to_download[] = $absolute_asset_url; 
                        } 
                    }
                    else { 
                        $this->log_status( sprintf( __( 'Skipping queuing Font Awesome font found in CSS due to CDN option: %s', 'wp-static-exporter' ), $absolute_asset_url ) ); 
                    }
                    // --- URL Rewriting Logic for CSS ---
                    $new_path_in_css = '';
                    $asset_relative_url = str_replace( $this->site_url, '', $absolute_asset_url );
                    $asset_save_path = $this->temp_export_dir . '/assets/' . ltrim($asset_relative_url, '/');
                    $new_path_in_css = $this->get_relative_path( $css_save_path, $asset_save_path );
                    // --- End URL Rewriting Logic for CSS ---
                    $escaped_original_path = preg_quote( $original_path, '/' );
                    $css_content = preg_replace( '/url\(([\'"]?)' . $escaped_original_path . '([\'"]?)\)/i', 'url($1' . $new_path_in_css . '$2)', $css_content );
                }
            }
        }
        return $css_content;
    }

	/**
	 * Creates the final ZIP archive. Includes php-mailer if forms are converted.
	 *
	 * @param bool $convert_forms Whether forms were converted.
	 * @param string|null $final_recipient_email The email address for the mailer config.
	 * @return bool True on success, false on failure.
	 */
	private function create_zip( $convert_forms, $final_recipient_email ) {
        $this->log_status( __( 'Creating ZIP archive...', 'wp-static-exporter' ) );
        if ( ! class_exists( 'ZipArchive' ) ) { 
            $this->log_status( __( 'Error: ZipArchive class not found...', 'wp-static-exporter' ) ); 
            return false; 
        }
        $zip = new ZipArchive();
        if ( $zip->open( $this->zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) { 
            $this->log_status( sprintf( __( 'Error: Cannot open %s for writing.', 'wp-static-exporter' ), $this->zip_file_path ) ); 
            return false; 
        }
        if ( $convert_forms && ! empty( $final_recipient_email ) ) {
            $mailer_dest_dir = trailingslashit($this->temp_export_dir) . 'php-mailer';
            if ( ! wp_mkdir_p( $mailer_dest_dir ) ) { 
                $this->log_status( sprintf( __( 'Error: Could not create php-mailer directory in export: %s', 'wp-static-exporter' ), $mailer_dest_dir ) ); 
            }
            else {
                $config_content = "<?php\n/**\n * Mailer Configuration (Auto-generated by WP Static Exporter)\n */\n";
                $config_content .= '$recipient_email = ' . var_export($final_recipient_email, true) . ";\n?>\n";
                $config_path = trailingslashit($mailer_dest_dir) . 'mailer-config.php';
                if (file_put_contents($config_path, $config_content) === false) { 
                    $this->log_status( sprintf( __( 'Error: Could not write mailer config file to %s.', 'wp-static-exporter' ), $config_path ) ); 
                }
                else { 
                    $this->log_status( sprintf( __( 'Generated mailer config file: %s', 'wp-static-exporter' ), $config_path ) ); 
                }
                $sendmail_source = WP_STATIC_EXPORTER_PATH . 'php-mailer/sendmail.php';
                $sendmail_dest = trailingslashit($mailer_dest_dir) . 'sendmail.php';
                if ( file_exists($sendmail_source) ) { 
                    if ( ! copy( $sendmail_source, $sendmail_dest ) ) { 
                        $this->log_status( sprintf( __( 'Error: Could not copy sendmail.php to %s.', 'wp-static-exporter' ), $sendmail_dest ) ); 
                    }
                    else { 
                        $this->log_status( sprintf( __( 'Copied sendmail.php to export.', 'wp-static-exporter' ) ) ); 
                    }
                }
                else { 
                    $this->log_status( sprintf( __( 'Warning: sendmail.php source file not found at %s', 'wp-static-exporter' ), $sendmail_source ) ); 
                }
            }
        }
        $source = realpath( $this->temp_export_dir );
        if ( is_dir( $source ) ) {
            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
            foreach ( $files as $file ) { 
                $file = realpath( $file ); 
                $relativePath = substr( $file, strlen( $source ) + 1 ); 
                if ( is_dir( $file ) ) { 
                    $zip->addEmptyDir( $relativePath ); 
                } elseif ( is_file( $file ) ) { 
                    $zip->addFile( $file, $relativePath ); 
                } 
            }
        } else { 
            $this->log_status( sprintf( __( 'Error: Temporary export directory %s not found.', 'wp-static-exporter' ), $source ) ); 
            $zip->close(); 
            return false; 
        }
        $zip_status = $zip->close();
        if ( $zip_status ) { 
            $this->log_status( sprintf( __( 'ZIP archive created successfully: %s', 'wp-static-exporter' ), $this->zip_file_path ) ); 
            return true; 
        }
        else { 
            $this->log_status( __( 'Error: Failed to close ZIP archive.', 'wp-static-exporter' ) ); 
            return false; 
        }
	}

	/**
	 * Cleans up temporary files and directories.
	 */
	private function cleanup( $remove_zip = true ) {
        $this->log_status( __( 'Cleaning up temporary files...', 'wp-static-exporter' ) );
        if ( ! empty( $this->temp_export_dir ) && file_exists( $this->temp_export_dir ) ) { 
            $this->remove_directory( $this->temp_export_dir ); 
            $this->log_status( sprintf(__( 'Removed temporary directory: %s', 'wp-static-exporter'), $this->temp_export_dir) ); 
        }
        if ( $remove_zip && ! empty( $this->zip_file_path ) && file_exists( $this->zip_file_path ) ) { 
            unlink( $this->zip_file_path ); 
            $this->log_status( sprintf(__( 'Removed ZIP file: %s', 'wp-static-exporter'), $this->zip_file_path) ); 
        }
	}

    /**
     * Recursively removes a directory.
     */
    private function remove_directory( $dir ) {
        if ( ! is_dir( $dir ) ) { return false; }
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) { 
            ( is_dir( "$dir/$file" ) ) ? $this->remove_directory( "$dir/$file" ) : unlink( "$dir/$file" ); 
        }
        return rmdir( $dir );
    }

	/**
	 * Converts a relative URL to an absolute URL based on a base URL.
	 */
	private function make_absolute_url( $relative_url, $base_url ) {
		$relative_url = trim( $relative_url );
        $base_url = trim( $base_url );
		if ( empty($relative_url) ) return $base_url;
        if ( parse_url( $relative_url, PHP_URL_SCHEME ) != '' ) return $relative_url;
		if ( strpos( $relative_url, '//' ) === 0 ) { 
            $base_scheme = parse_url( $base_url, PHP_URL_SCHEME ); 
            return ($base_scheme ? $base_scheme : 'http') . ':' . $relative_url; 
        }
		$base_parts = parse_url( $base_url );
		if ( ! $base_parts || !isset($base_parts['scheme']) || !isset($base_parts['host']) ) {
            if ( strpos( $relative_url, '/' ) === 0 ) { 
                $site_parts = parse_url($this->site_url); 
                if ($site_parts && isset($site_parts['scheme']) && isset($site_parts['host'])) { 
                    return $site_parts['scheme'] . '://' . $site_parts['host'] . $relative_url; 
                }
            }
            return false;
        }
		if ( strpos( $relative_url, '/' ) === 0 ) { 
            return $base_parts['scheme'] . '://' . $base_parts['host'] . (isset($base_parts['port']) ? ':' . $base_parts['port'] : '') . $relative_url; 
        }
		$base_path = isset($base_parts['path']) ? $base_parts['path'] : '/';
		if ( substr( $base_path, -1 ) !== '/' ) { 
            $base_path = dirname( $base_path ); 
        }
		$base_path = rtrim( $base_path, '/' ) . '/';
		$absolute_path = $base_path . $relative_url;
        $parts = explode( '/', $absolute_path );
        $absolutes = [];
		foreach ( $parts as $part ) { 
            if ( '.' == $part || '' == $part) continue; 
            if ( '..' == $part ) { 
                array_pop( $absolutes ); 
            } else { 
                $absolutes[] = $part; 
            }
        }
		$resolved_path = '/' . implode( '/', $absolutes );
		return $base_parts['scheme'] . '://' . $base_parts['host'] . (isset($base_parts['port']) ? ':' . $base_parts['port'] : '') . $resolved_path;
	}

	/**
	 * Calculates the relative path between two absolute filesystem paths.
	 */
	private function get_relative_path( $from_path, $to_path ) {
		$from_path = str_replace( '\\', '/', $from_path );
        $to_path = str_replace( '\\', '/', $to_path );
		$from_dir = pathinfo( $from_path, PATHINFO_DIRNAME );
        $to_dir = pathinfo( $to_path, PATHINFO_DIRNAME );
        if ($from_dir === $to_dir) { 
            return basename($to_path); 
        }
		$from_parts = explode( '/', trim( $from_dir, '/' ) );
        $to_parts = explode( '/', trim( $to_dir, '/' ) );
        $from_parts = array_filter($from_parts);
        $to_parts = array_filter($to_parts);
		$common_prefix_length = 0;
        $max_length = min( count( $from_parts ), count( $to_parts ) );
		for ( $i = 0; $i < $max_length; $i++ ) { 
            if ( $from_parts[$i] === $to_parts[$i] ) { 
                $common_prefix_length++; 
            } else { 
                break; 
            } 
        }
		$up_levels = count( $from_parts ) - $common_prefix_length;
		$relative_parts = array_fill( 0, $up_levels, '..' );
		$down_parts = array_slice( $to_parts, $common_prefix_length );
		$relative_parts = array_merge( $relative_parts, $down_parts );
		$relative_parts[] = basename( $to_path );
		$relative_path = implode( '/', $relative_parts );
		return empty($relative_path) ? basename($to_path) : $relative_path;
	}

} // End class WP_Static_Exporter

?>
