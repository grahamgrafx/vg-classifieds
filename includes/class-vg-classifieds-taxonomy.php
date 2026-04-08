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
						'name' => __( 'Classified Categories', 'vg-classifieds' ),
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
