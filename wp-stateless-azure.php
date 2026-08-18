<?php
/**
 * Plugin Name: WP Stateless Azure
 * Plugin URI:  https://github.com/udx/wp-stateless-azure
 * Description: A minimal wp-stateless, native to Azure: uploads land in Azure Blob Storage and are served from the blob endpoint (or your CDN in front of it), so hosts stay disposable while WordPress keeps its normal local-file flow. wp-stateless's behavior model - modes, URL/srcset rewrite, delete mirroring, bulk sync - with zero vendor libraries. Works as a regular plugin or a must-use plugin.
 * Version:     1.0.0
 * Author:      UDX
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Configuration - environment variables win, then wp-config constants of
 * the same name:
 *   WP_MEDIA_ACCOUNT    storage account name
 *   WP_MEDIA_CONTAINER  public-read container
 *   WP_MEDIA_SECRET     a storage account key
 *   WP_MEDIA_BASE_URL   optional - served base URL override (CDN / Front Door)
 *   WP_MEDIA_MODE       disabled | backup | cdn | ephemeral (default cdn)
 *   WP_DISABLE_MEDIA_OFFLOAD  any truthy value parks the engine entirely
 *
 * Modes (the wp-stateless model):
 *   backup    - upload to Blob, keep local files, keep serving LOCAL URLs
 *   cdn       - upload, keep local files, serve BLOB URLs (the default)
 *   ephemeral - upload, DELETE the local files, serve BLOB URLs. Regenerate-
 *               thumbnails style operations need the local file back, which
 *               this plugin does not re-download - that is the mode's deal.
 *
 * Auth is hand-rolled SharedKey against the Blob REST API - no SDK. Whole
 * files are buffered in memory for the PUT: images are small, giant video
 * uploads are the documented limit. Offload failures only ever error_log -
 * an upload must NEVER break because Azure is unreachable.
 */

/**
 * Read a setting: environment first, then a wp-config constant, then (mode
 * only) the saved option. Environment wins so operators can rotate without
 * a deploy.
 */
function wp_stateless_azure_setting( $name ) {
	$value = getenv( $name );
	if ( is_string( $value ) && '' !== $value ) {
		return $value;
	}
	if ( defined( $name ) ) {
		return (string) constant( $name );
	}
	if ( 'WP_MEDIA_MODE' === $name ) {
		return (string) get_option( 'wp_stateless_azure_mode', '' );
	}
	return '';
}

function wp_stateless_azure_mode() {
	$mode = strtolower( trim( wp_stateless_azure_setting( 'WP_MEDIA_MODE' ) ) );
	return in_array( $mode, array( 'disabled', 'backup', 'cdn', 'ephemeral' ), true ) ? $mode : 'cdn';
}

/**
 * Credentials present and the engine not parked. The mode is deliberately
 * NOT consulted here beyond `disabled`: backup still uploads, and the admin
 * page must render regardless. Callers that rewrite URLs or delete local
 * files consult the mode themselves.
 */
function wp_stateless_azure_ready() {
	if ( getenv( 'WP_PATH_PREFIX' ) ) {
		return false; // Cloud WP branch preview - previews never offload
	}
	if ( in_array( getenv( 'WP_DISABLE_MEDIA_OFFLOAD' ), array( 'true', '1' ), true ) ) {
		return false;
	}
	if ( 'disabled' === wp_stateless_azure_mode() ) {
		return false;
	}
	return wp_stateless_azure_setting( 'WP_MEDIA_ACCOUNT' )
		&& wp_stateless_azure_setting( 'WP_MEDIA_CONTAINER' )
		&& wp_stateless_azure_setting( 'WP_MEDIA_SECRET' );
}

/**
 * The blob endpoint the site's media is served from. WP_MEDIA_BASE_URL
 * overrides it wholesale (a CDN / Front Door in front of the container).
 */
function wp_stateless_azure_base_url() {
	$override = wp_stateless_azure_setting( 'WP_MEDIA_BASE_URL' );
	if ( $override ) {
		return rtrim( $override, '/' );
	}
	return 'https://' . wp_stateless_azure_setting( 'WP_MEDIA_ACCOUNT' ) . '.blob.core.windows.net/' . wp_stateless_azure_setting( 'WP_MEDIA_CONTAINER' );
}

/**
 * One signed Blob REST request (PUT upload or DELETE). SharedKey auth per
 * the 2018+ canonicalization: VERB, the Content- and conditional header
 * lines (empty here except Content-Length and Content-Type), the sorted
 * canonicalized x-ms-* headers, then /<account>/<container>/<blob> as the
 * resource. Returns true on a 2xx, false (after error_log) on anything else.
 */
function wp_stateless_azure_request( $method, $rel_path, $body, $content_type ) {
	$account = wp_stateless_azure_setting( 'WP_MEDIA_ACCOUNT' );
	$date    = gmdate( 'D, d M Y H:i:s' ) . ' GMT';
	$xms     = array(
		'x-ms-date'    => $date,
		'x-ms-version' => '2021-08-06',
	);
	if ( 'PUT' === $method ) {
		$xms['x-ms-blob-type'] = 'BlockBlob';
	}
	ksort( $xms );
	$canonical_headers = '';
	foreach ( $xms as $header => $value ) {
		$canonical_headers .= $header . ':' . $value . "\n";
	}
	$length         = is_string( $body ) ? strlen( $body ) : 0;
	$string_to_sign = $method . "\n\n\n" . ( $length ? $length : '' ) . "\n\n" . $content_type . "\n\n\n\n\n\n\n"
		. $canonical_headers . '/' . $account . '/' . wp_stateless_azure_setting( 'WP_MEDIA_CONTAINER' ) . '/' . $rel_path;
	$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( wp_stateless_azure_setting( 'WP_MEDIA_SECRET' ) ), true ) );
	$headers        = array( 'Authorization' => 'SharedKey ' . $account . ':' . $signature );
	foreach ( $xms as $header => $value ) {
		$headers[ $header ] = $value;
	}
	if ( '' !== $content_type ) {
		$headers['Content-Type'] = $content_type;
	}
	$args = array(
		'method'  => $method,
		'headers' => $headers,
		'timeout' => 60,
	);
	if ( is_string( $body ) ) {
		$args['body'] = $body;
	}
	// Blob names are URL-encoded per path segment for the request line; the
	// signature keeps the raw path (Azure canonicalizes the DECODED URI).
	$url      = wp_stateless_azure_base_url() . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel_path ) ) );
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'wp-stateless-azure: ' . $method . ' ' . $rel_path . ' failed: ' . $response->get_error_message() );
		return false;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'wp-stateless-azure: ' . $method . ' ' . $rel_path . ' returned HTTP ' . $status );
		return false;
	}
	return true;
}

/**
 * Upload one basedir-relative file ("2026/08/photo.jpg") as a block blob.
 * PUT overwrites, so a re-upload is idempotent; the per-request static only
 * skips re-PUTs when several hooks fire for the same file in one request.
 */
function wp_stateless_azure_upload( $rel_path ) {
	static $done = array();
	if ( isset( $done[ $rel_path ] ) ) {
		return $done[ $rel_path ];
	}
	$uploads = wp_upload_dir();
	$file    = trailingslashit( $uploads['basedir'] ) . $rel_path;
	if ( ! is_file( $file ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'wp-stateless-azure: skipping upload of missing file ' . $rel_path );
		$done[ $rel_path ] = false;
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$body = file_get_contents( $file );
	if ( false === $body ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'wp-stateless-azure: skipping upload of unreadable file ' . $rel_path );
		$done[ $rel_path ] = false;
		return false;
	}
	$mime              = wp_check_filetype( basename( $file ) );
	$content_type      = ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';
	$done[ $rel_path ] = wp_stateless_azure_request( 'PUT', $rel_path, $body, $content_type );
	return $done[ $rel_path ];
}

/**
 * The attachment's basedir-relative main file, or '' when unknown. Lives in
 * the _wp_attached_file meta for EVERY attachment - the metadata array only
 * exists for images, so PDFs & co. resolve here too.
 */
function wp_stateless_azure_relative_file( $post_id ) {
	$file = get_post_meta( $post_id, '_wp_attached_file', true );
	return is_string( $file ) ? $file : '';
}

/**
 * The offload marker. Current key wins; the Cloud WP v1 key
 * (_cloud_wp_media_offloaded, pre-extraction) is honored so attachments
 * offloaded before the plugin became standalone keep serving from Azure.
 */
function wp_stateless_azure_is_offloaded( $post_id ) {
	if ( get_post_meta( $post_id, '_wp_stateless_azure_offloaded', true ) ) {
		return true;
	}
	return (bool) get_post_meta( $post_id, '_cloud_wp_media_offloaded', true );
}

function wp_stateless_azure_mark_offloaded( $post_id ) {
	update_post_meta( $post_id, '_wp_stateless_azure_offloaded', 1 );
}

/**
 * All local files for one attachment: the base file plus every generated
 * size (same directory). Returns basedir-relative paths.
 */
function wp_stateless_azure_attachment_files( $post_id ) {
	$rel   = wp_stateless_azure_relative_file( $post_id );
	$files = array();
	if ( '' === $rel ) {
		return $files;
	}
	$files[] = $rel;
	$meta    = wp_get_attachment_metadata( $post_id );
	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$dir    = dirname( $rel );
		$prefix = '.' === $dir ? '' : $dir . '/';
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $prefix . $size['file'];
			}
		}
	}
	return array_unique( $files );
}

/**
 * Upload every file of one attachment; returns true when the base file
 * landed (sizes may individually fail - they only log). Marks the
 * attachment offloaded on success. In ephemeral mode each local copy is
 * deleted once its PUT landed; the $unlinking guard keeps our own delete
 * from triggering the blob-delete mirror below.
 */
function wp_stateless_azure_offload_attachment( $post_id ) {
	$rel = wp_stateless_azure_relative_file( $post_id );
	if ( '' === $rel ) {
		return false;
	}
	if ( ! wp_stateless_azure_upload( $rel ) ) {
		return false;
	}
	$files = wp_stateless_azure_attachment_files( $post_id );
	foreach ( array_diff( $files, array( $rel ) ) as $size_rel ) {
		wp_stateless_azure_upload( $size_rel );
	}
	wp_stateless_azure_mark_offloaded( $post_id );
	if ( 'ephemeral' === wp_stateless_azure_mode() ) {
		global $wp_stateless_azure_unlinking;
		$wp_stateless_azure_unlinking = true;
		$uploads = wp_upload_dir();
		foreach ( $files as $f ) {
			$local = trailingslashit( $uploads['basedir'] ) . $f;
			if ( is_file( $local ) ) {
				wp_delete_file( $local );
			}
		}
		$wp_stateless_azure_unlinking = false;
	}
	return true;
}

// Images: upload the base file plus every generated size (same directory).
add_filter( 'wp_generate_attachment_metadata', 'wp_stateless_azure_generate_metadata', 10, 2 );

/**
 * @param array|mixed $metadata
 * @param int         $attachment_id
 * @return array|mixed unchanged either way - the upload is a side effect.
 */
function wp_stateless_azure_generate_metadata( $metadata, $attachment_id ) {
	if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
		return $metadata;
	}
	if ( wp_stateless_azure_ready() ) {
		wp_stateless_azure_offload_attachment( $attachment_id );
	}
	return $metadata;
}

// Non-image attachments (PDFs & co.) get no generated metadata; upload the
// attached file as-is.
add_action( 'add_attachment', 'wp_stateless_azure_add_attachment' );

function wp_stateless_azure_add_attachment( $post_id ) {
	if ( ! wp_stateless_azure_ready() ) {
		return;
	}
	wp_stateless_azure_offload_attachment( $post_id );
}

// Serve offloaded attachments from the blob endpoint, not the pod. Backup
// mode keeps local URLs - that is its entire point.
add_filter( 'wp_get_attachment_url', 'wp_stateless_azure_attachment_url', 10, 2 );

function wp_stateless_azure_attachment_url( $url, $post_id ) {
	if ( ! wp_stateless_azure_ready() || 'backup' === wp_stateless_azure_mode() ) {
		return $url;
	}
	if ( ! wp_stateless_azure_is_offloaded( $post_id ) ) {
		return $url;
	}
	$rel = wp_stateless_azure_relative_file( $post_id );
	if ( '' === $rel ) {
		return $url;
	}
	return wp_stateless_azure_base_url() . '/' . $rel;
}

// srcset sources are per-size files next to the base file; match each
// source to its size by WIDTH (sources are keyed by width) and rewrite.
add_filter( 'wp_calculate_image_srcset', 'wp_stateless_azure_srcset', 10, 5 );

function wp_stateless_azure_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	if ( ! wp_stateless_azure_ready() || 'backup' === wp_stateless_azure_mode() ) {
		return $sources;
	}
	if ( ! wp_stateless_azure_is_offloaded( $attachment_id ) ) {
		return $sources;
	}
	if ( ! is_array( $image_meta ) || empty( $image_meta['file'] ) || empty( $image_meta['sizes'] ) || ! is_array( $image_meta['sizes'] ) ) {
		return $sources;
	}
	$dir    = dirname( $image_meta['file'] );
	$prefix = '.' === $dir ? '' : $dir . '/';
	foreach ( $sources as $width => $source ) {
		foreach ( $image_meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) && isset( $size['width'] ) && (int) $size['width'] === (int) $width ) {
				$sources[ $width ]['url'] = wp_stateless_azure_base_url() . '/' . $prefix . $size['file'];
				break;
			}
		}
	}
	return $sources;
}

// Attachment deletion deletes every local size; mirror each deletion to the
// blob (best-effort - failures only log). Return $file unchanged so the
// local delete proceeds either way. The $unlinking guard: ephemeral mode's
// own local cleanup passes through this same filter, and that unlink must
// NOT delete the blob the site now depends on.
add_filter( 'wp_delete_file', 'wp_stateless_azure_delete_file' );

function wp_stateless_azure_delete_file( $file ) {
	if ( ! is_string( $file ) || '' === $file ) {
		return $file;
	}
	if ( ! wp_stateless_azure_ready() ) {
		return $file;
	}
	global $wp_stateless_azure_unlinking;
	if ( ! empty( $wp_stateless_azure_unlinking ) ) {
		return $file;
	}
	$uploads = wp_upload_dir();
	$basedir = trailingslashit( $uploads['basedir'] );
	if ( 0 === strpos( $file, $basedir ) ) {
		wp_stateless_azure_request( 'DELETE', substr( $file, strlen( $basedir ) ), null, '' );
	}
	return $file;
}

/**
 * Bulk sync over ALL attachments, oldest first, skipping the already-
 * offloaded in PHP. Deliberately NOT a NOT-EXISTS meta query: marking an
 * item drains it from such a result set, so an advancing offset would skip
 * every second item. A stable, unfiltered set paginates correctly.
 */
function wp_stateless_azure_sync_batch( $limit = 25, $offset = 0 ) {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
	) );
	$uploaded = 0;
	$skipped  = 0;
	foreach ( $q->posts as $id ) {
		if ( wp_stateless_azure_is_offloaded( $id ) ) {
			$skipped++;
			continue;
		}
		if ( wp_stateless_azure_offload_attachment( $id ) ) {
			$uploaded++;
		}
	}
	return array( 'processed' => count( $q->posts ), 'uploaded' => $uploaded, 'already' => $skipped );
}

function wp_stateless_azure_count_unsynced() {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array( 'key' => '_wp_stateless_azure_offloaded', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_stateless_azure_offloaded', 'value' => '0', 'compare' => '=' ),
			),
			array(
				'relation' => 'OR',
				array( 'key' => '_cloud_wp_media_offloaded', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_cloud_wp_media_offloaded', 'value' => '0', 'compare' => '=' ),
			),
		),
	) );
	return (int) $q->found_posts;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'stateless-azure sync', function ( $args, $assoc ) {
		if ( ! wp_stateless_azure_ready() ) {
			WP_CLI::error( 'offload is not provisioned (WP_MEDIA_* missing, mode disabled, or a preview)' );
		}
		$batch    = isset( $assoc['batch'] ) ? max( 1, (int) $assoc['batch'] ) : 100;
		$offset   = 0;
		$total    = 0;
		$uploaded = 0;
		for ( ;; ) {
			$r        = wp_stateless_azure_sync_batch( $batch, $offset );
			$total   += $r['processed'];
			$uploaded += $r['uploaded'];
			$offset  += $batch;
			WP_CLI::log( "processed {$total}..." );
			if ( $r['processed'] < $batch ) {
				break;
			}
		}
		WP_CLI::success( "sync complete: {$uploaded} attachments offloaded ({$total} checked)" );
	} );
}

add_action( 'admin_menu', 'wp_stateless_azure_admin_menu' );

function wp_stateless_azure_admin_menu() {
	add_options_page( 'WP Stateless Azure', 'WP Stateless Azure', 'manage_options', 'wp-stateless-azure', 'wp_stateless_azure_admin_page' );
}

function wp_stateless_azure_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$posted_mode = isset( $_POST['wpsaz_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpsaz_mode'] ) ) : '';
	if ( $posted_mode && check_admin_referer( 'wp_stateless_azure_settings' ) ) {
		if ( in_array( $posted_mode, array( 'disabled', 'backup', 'cdn', 'ephemeral' ), true ) ) {
			update_option( 'wp_stateless_azure_mode', $posted_mode );
			echo '<div class="notice notice-success"><p>Mode saved.</p></div>';
		}
	}
	$account   = wp_stateless_azure_setting( 'WP_MEDIA_ACCOUNT' );
	$container = wp_stateless_azure_setting( 'WP_MEDIA_CONTAINER' );
	$mode      = wp_stateless_azure_mode();
	$ready     = wp_stateless_azure_ready();
	echo '<div class="wrap"><h1>WP Stateless Azure</h1>';
	if ( $ready ) {
		printf(
			'<p><strong>Active (%s mode).</strong> Uploads go to container <code>%s</code> on storage account <code>%s</code>%s. Deleting an attachment deletes its blobs.</p>',
			esc_html( $mode ),
			esc_html( $container ),
			esc_html( $account ),
			esc_html( wp_stateless_azure_setting( 'WP_MEDIA_BASE_URL' ) ? ' and are served via ' . wp_stateless_azure_setting( 'WP_MEDIA_BASE_URL' ) : '' )
		);
	} else {
		echo '<p><strong>Not provisioned.</strong> Set <code>WP_MEDIA_ACCOUNT</code>, <code>WP_MEDIA_CONTAINER</code> and <code>WP_MEDIA_SECRET</code> as environment variables or wp-config constants, pointing at a storage account and a public-read container. On Cloud WP this is one click - site &rarr; Storage &rarr; Offload to Azure Blob.</p>';
	}

	// Mode picker. An env/constant override pins the mode - the option then
	// only documents intent, so say so instead of pretending the select rules.
	$pinned = (bool) ( getenv( 'WP_MEDIA_MODE' ) || defined( 'WP_MEDIA_MODE' ) );
	echo '<form method="post"><h2>Mode</h2>';
	wp_nonce_field( 'wp_stateless_azure_settings' );
	echo '<select name="wpsaz_mode"' . ( $pinned ? ' disabled' : '' ) . '>';
	foreach ( array(
		'disabled'  => 'Disabled - nothing uploads, nothing rewrites',
		'backup'    => 'Backup - upload to Blob, keep serving local URLs',
		'cdn'       => 'CDN - upload, serve Blob URLs, keep local copies',
		'ephemeral' => 'Ephemeral - upload, serve Blob URLs, delete local copies',
	) as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $mode, $value, false ), esc_html( $label ) );
	}
	echo '</select> ';
	submit_button( 'Save mode', 'secondary', 'submit', false );
	if ( $pinned ) {
		echo '<p class="description">Mode is pinned by the WP_MEDIA_MODE environment variable / constant.</p>';
	}
	echo '</form>';

	if ( $ready ) {
		$remaining = wp_stateless_azure_count_unsynced();
		printf( '<h2>Bulk sync</h2><p><strong>%d</strong> attachments are not offloaded yet.</p>', (int) $remaining );
		if ( $remaining > 0 ) {
			echo '<button class="button button-primary" id="wpsaz-sync">Sync existing media now</button> <span id="wpsaz-sync-status"></span>';
			echo '<script>(function(){var b=document.getElementById("wpsaz-sync"),s=document.getElementById("wpsaz-sync-status");if(!b)return;b.onclick=function(){b.disabled=true;var off=0;var step=function(){var f=new FormData();f.append("action","wpsaz_sync");f.append("nonce",'
				. wp_json_encode( wp_create_nonce( 'wpsaz_sync' ) )
				. ');f.append("offset",off);fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(d){if(!d||!d.success){s.textContent=" sync failed - check the PHP error log";return;}off+=d.data.processed;s.textContent=" "+off+" checked, "+d.data.uploaded+" uploaded in the last batch";if(d.data.processed===25){step();}else{s.textContent=" done - "+off+" attachments checked.";}}).catch(function(){s.textContent=" request failed";});};step();};})();</script>';
		}
	}
	echo '</div>';
}

add_action( 'wp_ajax_wpsaz_sync', 'wp_stateless_azure_ajax_sync' );

function wp_stateless_azure_ajax_sync() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'wpsaz_sync', 'nonce', false ) ) {
		wp_send_json_error( array(), 403 );
	}
	if ( ! wp_stateless_azure_ready() ) {
		wp_send_json_error( array( 'error' => 'not provisioned' ), 400 );
	}
	$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
	wp_send_json_success( wp_stateless_azure_sync_batch( 25, $offset ) );
}
