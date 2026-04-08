<?php
/**
 * Plugin Name: vg-classifieds
 * Description: Imports a ZIP of HTML classifieds into a Classified custom post type.
 * Version:     0.1.0
 * Author:      Graham Smith
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NCI_Classifieds_Importer {
  const CPT = 'classified';
  const META_SOURCE_FILE = '_nci_source_file';
  const META_SOURCE_HASH = '_nci_source_hash';
  const META_RAW_HTML     = '_nci_raw_html';

  public static function init() {
    add_action( 'init', [ __CLASS__, 'register_cpt' ] );
    add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
  }

  public static function register_cpt() {
    register_post_type(self::CPT, [
      'label' => 'Classifieds',
      'public' => true,
      'show_in_rest' => true, // Gutenberg + REST
      'supports' => [ 'title', 'editor', 'excerpt', 'revisions' ],
      'has_archive' => true,
      'rewrite' => [ 'slug' => 'classifieds' ],
      'menu_icon' => 'dashicons-megaphone',
    ]);
  }

  public static function admin_menu() {
    add_management_page(
      'Classifieds Import',
      'Classifieds Import',
      'manage_options',
      'nci-classifieds-import',
      [ __CLASS__, 'render_import_page' ]
    );
  }

  public static function render_import_page() {
    if ( ! current_user_can('manage_options') ) {
      wp_die('Insufficient permissions.');
    }

    // Handle form POST
    if ( isset($_POST['nci_import']) ) {
      check_admin_referer('nci_import_zip');

      $result = self::handle_zip_upload();
      echo '<div class="notice notice-info"><p>' . esc_html($result['message']) . '</p></div>';

      if ( ! empty($result['rows']) ) {
        echo '<table class="widefat"><thead><tr><th>File</th><th>Status</th><th>Post</th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
          echo '<tr>';
          echo '<td>' . esc_html($row['file']) . '</td>';
          echo '<td>' . esc_html($row['status']) . '</td>';
          echo '<td>' . ($row['post_id'] ? '<a href="' . esc_url(get_edit_post_link($row['post_id'])) . '">Edit #' . intval($row['post_id']) . '</a>' : '-') . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table>';
      }
    }

    ?>
    <div class="wrap">
      <h1>Classifieds Import</h1>
      <p>Upload a ZIP containing HTML files. Each HTML file becomes a Classified post.</p>

      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('nci_import_zip'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="nci_zip">ZIP file</label></th>
            <td><input type="file" id="nci_zip" name="nci_zip" accept=".zip" required /></td>
          </tr>
          <tr>
            <th scope="row">Publish status</th>
            <td>
              <select name="nci_status">
                <option value="draft" selected>Draft</option>
                <option value="publish">Publish</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">HTML handling</th>
            <td>
              <label><input type="checkbox" name="nci_trusted_html" value="1" /> Treat HTML as trusted (admins only)</label>
            </td>
          </tr>
        </table>

        <p><button class="button button-primary" name="nci_import" value="1">Import</button></p>
      </form>
    </div>
    <?php
  }

  private static function handle_zip_upload() {
    if ( empty($_FILES['nci_zip']['tmp_name']) ) {
      return [ 'message' => 'No ZIP uploaded.', 'rows' => [] ];
    }

    $status = isset($_POST['nci_status']) ? sanitize_key($_POST['nci_status']) : 'draft';
    $trusted = ! empty($_POST['nci_trusted_html']) && current_user_can('manage_options');

    $tmp = $_FILES['nci_zip']['tmp_name'];
    $zip = new ZipArchive();

    if ( true !== $zip->open($tmp) ) {
      return [ 'message' => 'Could not open ZIP.', 'rows' => [] ];
    }

    $rows = [];
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
      $name = $zip->getNameIndex($i);

      // Only .html/.htm files
      if ( ! preg_match('/\.(html?|HTML?)$/', $name) ) continue;

      $raw = $zip->getFromIndex($i);
      if ( false === $raw || '' === trim($raw) ) {
        $rows[] = [ 'file' => $name, 'status' => 'Skipped (empty)', 'post_id' => null ];
        continue;
      }

      $hash = hash('sha256', $raw);

      // Find existing post by source filename
      $existing = self::find_existing_by_source($name);

      // If unchanged, skip
      if ( $existing && get_post_meta($existing, self::META_SOURCE_HASH, true) === $hash ) {
        $rows[] = [ 'file' => $name, 'status' => 'Skipped (unchanged)', 'post_id' => $existing ];
        continue;
      }

      $title = self::title_from_html_or_filename($raw, $name);

      $content = $trusted
        ? $raw
        : wp_kses_post($raw);

      $postarr = [
        'post_type'   => self::CPT,
        'post_status' => in_array($status, ['draft','publish'], true) ? $status : 'draft',
        'post_title'  => $title,
        'post_content'=> $content,
      ];

      if ( $existing ) {
        $postarr['ID'] = $existing;
        $post_id = wp_update_post($postarr, true);
        $action = is_wp_error($post_id) ? 'Error updating' : 'Updated';
      } else {
        $post_id = wp_insert_post($postarr, true);
        $action = is_wp_error($post_id) ? 'Error creating' : 'Created';
      }

      if ( is_wp_error($post_id) ) {
        $rows[] = [ 'file' => $name, 'status' => $action . ': ' . $post_id->get_error_message(), 'post_id' => null ];
        continue;
      }

      update_post_meta($post_id, self::META_SOURCE_FILE, $name);
      update_post_meta($post_id, self::META_SOURCE_HASH, $hash);
      update_post_meta($post_id, self::META_RAW_HTML, $raw); // keep original for debugging

      $rows[] = [ 'file' => $name, 'status' => $action, 'post_id' => $post_id ];
    }

    $zip->close();

    return [
      'message' => 'Import complete. Processed ' . count($rows) . ' HTML files.',
      'rows' => $rows
    ];
  }

  private static function find_existing_by_source($filename) {
    $q = new WP_Query([
      'post_type' => self::CPT,
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [[
        'key' => self::META_SOURCE_FILE,
        'value' => $filename,
        'compare' => '='
      ]]
    ]);
    return ! empty($q->posts[0]) ? intval($q->posts[0]) : 0;
  }

  private static function title_from_html_or_filename($html, $filename) {
    if ( preg_match('/<title>(.*?)<\/title>/is', $html, $m) ) {
      $t = wp_strip_all_tags($m[1]);
      if ( $t ) return $t;
    }
    $base = preg_replace('/\.(html?|HTML?)$/', '', basename($filename));
    $base = str_replace(['_','-'], ' ', $base);
    return ucwords(trim($base));
  }
}

NCI_Classifieds_Importer::init();
