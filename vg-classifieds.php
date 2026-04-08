<?php
/**
 * Plugin Name:       vg-classifieds
 * Plugin URI:        https://github.com/grahamgrafx/vg-classifieds
 * Description:       Imports a ZIP of HTML classifieds into a Classified custom post type.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Graham Smith
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vg-classifieds
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VG_CLASSIFIEDS_VERSION', '0.1.0' );
define( 'VG_CLASSIFIEDS_FILE', __FILE__ );
define( 'VG_CLASSIFIEDS_PATH', plugin_dir_path( __FILE__ ) );

require_once VG_CLASSIFIEDS_PATH . 'includes/class-vg-classifieds-taxonomy.php';

/**
 * Flush rewrite rules after CPT and taxonomy are registered (new installs / permalink changes).
 */
function vg_classifieds_activate() {
	if ( class_exists( 'NCI_Classifieds_Importer', false ) ) {
		NCI_Classifieds_Importer::register_cpt();
	}
	if ( class_exists( 'VG_Classifieds_Taxonomy', false ) ) {
		VG_Classifieds_Taxonomy::register();
	}
	flush_rewrite_rules();
}

function vg_classifieds_deactivate() {
	flush_rewrite_rules();
}

register_activation_hook( VG_CLASSIFIEDS_FILE, 'vg_classifieds_activate' );
register_deactivation_hook( VG_CLASSIFIEDS_FILE, 'vg_classifieds_deactivate' );

if ( ! class_exists( 'NCI_Classifieds_Importer', false ) ) {

	class NCI_Classifieds_Importer {
		const CPT              = 'vg_classified';
		const META_SOURCE_FILE = '_nci_source_file';
		const META_SOURCE_HASH = '_nci_source_hash';
		const META_RAW_HTML    = '_nci_raw_html';

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_cpt' ) );
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice_missing_zip' ) );
		}

		public static function register_cpt() {
			register_post_type(
				self::CPT,
				array(
					'labels'       => array(
						'name' => __( 'Classifieds', 'vg-classifieds' ),
					),
					'public'       => true,
					'show_in_rest' => true,
					'supports'     => array( 'title', 'editor', 'excerpt', 'revisions' ),
					'has_archive'  => true,
					'rewrite'      => array( 'slug' => 'classifieds' ),
					'menu_icon'    => 'dashicons-megaphone',
				)
			);
		}

		public static function admin_menu() {
			add_submenu_page(
				'edit.php?post_type=' . self::CPT,
				__( 'Classifieds Import', 'vg-classifieds' ),
				__( 'Classifieds Import', 'vg-classifieds' ),
				'manage_options',
				'nci-classifieds-import',
				array( __CLASS__, 'render_import_page' )
			);
		}

		public static function admin_notice_missing_zip() {
			if ( ! current_user_can( 'manage_options' ) || class_exists( 'ZipArchive', false ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$show   = $screen && (
				( isset( $screen->id ) && false !== strpos( $screen->id, 'vg_classified' ) )
				|| ( isset( $screen->base ) && 'plugins' === $screen->base )
			);
			if ( $show ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'vg-classifieds: The PHP Zip extension (ZipArchive) is required for ZIP imports. Ask your host to enable it.', 'vg-classifieds' );
				echo '</p></div>';
			}
		}

		public static function render_import_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'vg-classifieds' ) );
			}

			if ( isset( $_POST['nci_import'] ) ) {
				check_admin_referer( 'nci_import_zip' );

				$result = self::handle_zip_upload();
				echo '<div class="notice notice-info"><p>' . esc_html( $result['message'] ) . '</p></div>';

				if ( ! empty( $result['rows'] ) ) {
					echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'File', 'vg-classifieds' ) . '</th><th>' . esc_html__( 'Status', 'vg-classifieds' ) . '</th><th>' . esc_html__( 'Post', 'vg-classifieds' ) . '</th></tr></thead><tbody>';
					foreach ( $result['rows'] as $row ) {
						echo '<tr>';
						echo '<td>' . esc_html( $row['file'] ) . '</td>';
						echo '<td>' . esc_html( $row['status'] ) . '</td>';
						echo '<td>' . ( $row['post_id'] ? '<a href="' . esc_url( get_edit_post_link( $row['post_id'] ) ) . '">' . esc_html( sprintf( /* translators: %s: post ID */ __( 'Edit #%s', 'vg-classifieds' ), (string) intval( $row['post_id'] ) ) ) . '</a>' : '-' ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
				}
			}

			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Classifieds Import', 'vg-classifieds' ); ?></h1>
				<p><?php echo esc_html__( 'Upload a ZIP containing HTML files. Each HTML file becomes a Classified post.', 'vg-classifieds' ); ?></p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'nci_import_zip' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nci_zip"><?php echo esc_html__( 'ZIP file', 'vg-classifieds' ); ?></label></th>
							<td><input type="file" id="nci_zip" name="nci_zip" accept=".zip,application/zip" required /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Publish status', 'vg-classifieds' ); ?></th>
							<td>
								<select name="nci_status">
									<option value="draft" selected><?php echo esc_html__( 'Draft', 'vg-classifieds' ); ?></option>
									<option value="publish"><?php echo esc_html__( 'Publish', 'vg-classifieds' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'HTML handling', 'vg-classifieds' ); ?></th>
							<td>
								<label><input type="checkbox" name="nci_trusted_html" value="1" /> <?php echo esc_html__( 'Treat HTML as trusted (admins only)', 'vg-classifieds' ); ?></label>
							</td>
						</tr>
					</table>

					<p><button class="button button-primary" name="nci_import" value="1"><?php echo esc_html__( 'Import', 'vg-classifieds' ); ?></button></p>
				</form>
			</div>
			<?php
		}

		private static function handle_zip_upload() {
			if ( ! class_exists( 'ZipArchive', false ) ) {
				return array(
					'message' => __( 'ZIP imports require the PHP Zip extension (ZipArchive). Contact your host to enable it.', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}

			if ( empty( $_FILES['nci_zip'] ) || ! is_array( $_FILES['nci_zip'] ) ) {
				return array(
					'message' => __( 'No ZIP uploaded.', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}

			if ( isset( $_FILES['nci_zip']['error'] ) && UPLOAD_ERR_OK !== (int) $_FILES['nci_zip']['error'] ) {
				return array(
					'message' => __( 'ZIP upload failed (upload error).', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}

			if ( empty( $_FILES['nci_zip']['tmp_name'] ) || ! is_uploaded_file( $_FILES['nci_zip']['tmp_name'] ) ) {
				return array(
					'message' => __( 'Invalid upload. Try again.', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}

			$status  = isset( $_POST['nci_status'] ) ? sanitize_key( wp_unslash( $_POST['nci_status'] ) ) : 'draft';
			$trusted = ! empty( $_POST['nci_trusted_html'] ) && current_user_can( 'manage_options' );

			$tmp      = $_FILES['nci_zip']['tmp_name'];
			$zip_name = isset( $_FILES['nci_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['nci_zip']['name'] ) ) : '';
			$ftype    = wp_check_filetype_and_ext( $tmp, $zip_name );

			if ( ! empty( $ftype['ext'] ) && 'zip' !== $ftype['ext'] ) {
				return array(
					'message' => __( 'File does not appear to be a ZIP.', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}
			$zip = new ZipArchive();

			if ( true !== $zip->open( $tmp ) ) {
				return array(
					'message' => __( 'Could not open ZIP.', 'vg-classifieds' ),
					'rows'    => array(),
				);
			}

			$rows = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );

				if ( ! $name || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
					continue;
				}

				if ( ! preg_match( '/\.(html?|HTML?)$/', $name ) ) {
					continue;
				}

				$raw = $zip->getFromIndex( $i );
				if ( false === $raw || '' === trim( $raw ) ) {
					$rows[] = array( 'file' => $name, 'status' => __( 'Skipped (empty)', 'vg-classifieds' ), 'post_id' => null );
					continue;
				}

				$hash = hash( 'sha256', $raw );

				$existing = self::find_existing_by_source( $name );

				if ( $existing && get_post_meta( $existing, self::META_SOURCE_HASH, true ) === $hash ) {
					$rows[] = array( 'file' => $name, 'status' => __( 'Skipped (unchanged)', 'vg-classifieds' ), 'post_id' => $existing );
					continue;
				}

				$title = self::title_from_html( $raw );

				$body_html = preg_replace( '/<!--\s*Classification Title Here\s*-->\s*[^\r\n]*\R?/i', '', $raw );
				$body_html = is_string( $body_html ) ? $body_html : $raw;

				$body_html = preg_replace( '/<!--.*?-->/s', '', $body_html );
				$body_html = is_string( $body_html ) ? $body_html : $raw;

				$content = $trusted ? $body_html : wp_kses_post( $body_html );

				$postarr = array(
					'post_type'    => self::CPT,
					'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
					'post_title'   => $title,
					'post_content' => $content,
				);

				if ( $existing ) {
					$postarr['ID'] = $existing;
					$post_id       = wp_update_post( $postarr, true );
					$action        = is_wp_error( $post_id ) ? __( 'Error updating', 'vg-classifieds' ) : __( 'Updated', 'vg-classifieds' );
				} else {
					$post_id = wp_insert_post( $postarr, true );
					$action  = is_wp_error( $post_id ) ? __( 'Error creating', 'vg-classifieds' ) : __( 'Created', 'vg-classifieds' );
				}

				if ( is_wp_error( $post_id ) ) {
					$rows[] = array( 'file' => $name, 'status' => $action . ': ' . $post_id->get_error_message(), 'post_id' => null );
					continue;
				}

				update_post_meta( $post_id, self::META_SOURCE_FILE, $name );
				update_post_meta( $post_id, self::META_SOURCE_HASH, $hash );
				update_post_meta( $post_id, self::META_RAW_HTML, $raw );

				$rows[] = array( 'file' => $name, 'status' => $action, 'post_id' => $post_id );
			}

			$zip->close();

			return array(
				'message' => sprintf(
					/* translators: %d: number of HTML files processed */
					__( 'Import complete. Processed %d HTML files.', 'vg-classifieds' ),
					count( $rows )
				),
				'rows'    => $rows,
			);
		}

		private static function find_existing_by_source( $filename ) {
			$q = new WP_Query(
				array(
					'post_type'      => self::CPT,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => self::META_SOURCE_FILE,
							'value'   => $filename,
							'compare' => '=',
						),
					),
				)
			);
			return ! empty( $q->posts[0] ) ? intval( $q->posts[0] ) : 0;
		}

		private static function title_from_html( $html ) {
			if ( preg_match( '/<!--\s*Classification Title Here\s*-->\s*([^\r\n]+)/i', $html, $m ) ) {
				$line = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
				$line = preg_replace( '/^\d+\s+/', '', $line );
				if ( $line ) {
					return ucwords( strtolower( $line ) );
				}
			}

			if ( preg_match( '/<title>(.*?)<\/title>/is', $html, $m ) ) {
				$t = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
				if ( $t ) {
					return ucwords( strtolower( $t ) );
				}
			}

			return __( 'Untitled Classified', 'vg-classifieds' );
		}
	}
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'vg-classifieds',
			false,
			dirname( plugin_basename( VG_CLASSIFIEDS_FILE ) ) . '/languages'
		);
		if ( class_exists( 'NCI_Classifieds_Importer', false ) ) {
			NCI_Classifieds_Importer::init();
		}
		if ( class_exists( 'VG_Classifieds_Taxonomy', false ) ) {
			VG_Classifieds_Taxonomy::init();
		}
	},
	5
);
