<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'VG_Classifieds_Taxonomy', false ) ) {

	class VG_Classifieds_Taxonomy {
		const TAXONOMY  = 'vg_classified_category';
		const POST_TYPE = 'vg_classified';

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register' ) );
		}

		public static function register() {
			register_taxonomy(
				self::TAXONOMY,
				array( self::POST_TYPE ),
				array(
					'labels'       => array(
						'name'              => __( 'Classified Categories', 'vg-classifieds' ),
						'singular_name'     => __( 'Classified Category', 'vg-classifieds' ),
						'search_items'      => __( 'Search Classified Categories', 'vg-classifieds' ),
						'all_items'         => __( 'All Classified Categories', 'vg-classifieds' ),
						'parent_item'       => __( 'Parent Classified Category', 'vg-classifieds' ),
						'parent_item_colon' => __( 'Parent Classified Category:', 'vg-classifieds' ),
						'edit_item'         => __( 'Edit Classified Category', 'vg-classifieds' ),
						'update_item'       => __( 'Update Classified Category', 'vg-classifieds' ),
						'add_new_item'      => __( 'Add New Classified Category', 'vg-classifieds' ),
						'new_item_name'     => __( 'New Classified Category Name', 'vg-classifieds' ),
						'menu_name'         => __( 'Classified Categories', 'vg-classifieds' ),
					),
					'public'       => true,
					'hierarchical' => true,
					'show_in_rest' => true,
					'rewrite'      => array( 'slug' => 'classified-category' ),
				)
			);
		}
	}
}
