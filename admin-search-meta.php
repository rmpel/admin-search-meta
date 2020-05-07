<?php
/*
Plugin Name: Admin Search Meta
Plugin URI: https://github.com/rmpel/admin-search-meta
Version: 0.0.2
Author: Remon Pel
Description: Allow filtering your posts-panels by meta. Not to be confused with Admin Meta Search, which did a small portion of what this plugin does, and is defunct for over 6 years.
Requires PHP: 5.6.0
Requires at least: 4.0
Textdomain: rmp_asm
*/

namespace RemonPel\Tools;

class AdminSearchMeta {

	public static function getInstance() {
		static $instance;
		if ( ! $instance ) {
			$instance = new static();
		}

		return $instance;
	}

	public function __construct() {
		global $pagenow;

		add_action('plugins_loaded', function () {
		    load_plugin_textdomain( 'rmp_asm', false, plugin_basename(dirname(__FILE__)) . '/pomo' );
        });

		if ( is_admin() && $pagenow == 'edit.php' && isset( $_GET['post_type'] ) && ! empty( $_GET['s'] ) ) {
			add_filter( 'posts_join', function ( $join ) {
				return call_user_func( [ static::class, 'getInstance' ] )->posts_join( $join );
			} );
			add_filter( 'posts_where', function ( $where ) {
				return call_user_func( [ static::class, 'getInstance' ] )->posts_where( $where );
			} );
		}

		add_action( 'admin_menu', function () {
			add_menu_page( __( 'Admin Meta Search', 'rmp_asm' ), __( 'Admin Meta Search', 'rmp_asm' ), 'manage_options', 'rmp_asm', [
				static::class,
				'admin_page_callback'
			] );
		} );
	}

	private function alterations() {
		$alters = get_option( 'admin_search_meta_options', [] );
		$data   = isset( $alters[ $_GET['post_type'] ] ) ? $alters[ $_GET['post_type'] ] : false;
		if ( $data && $data['enabled'] ) {
			return ! empty( $data['fields'] ) ? $data['fields'] : true;
		}

		return false;
	}

	/**
	 * Join postmeta in admin post search
	 *
	 * @return string SQL join
	 */
	private function posts_join( $join ) {
		global $wpdb;
		$alterations = static::alterations();

		if ( $alterations ) {
			$join .= 'LEFT JOIN ' . $wpdb->postmeta . ' post_meta_admin_search ON ' . $wpdb->posts . '.ID = post_meta_admin_search.post_id ';
		}

		return $join;
	}


	/**
	 * Filtering the where clause in admin post search query
	 *
	 * @return string SQL WHERE
	 */
	private function posts_where( $where ) {
		global $wpdb;
		$alterations = static::alterations();

		if ( $alterations ) {
			if ( is_bool( $alterations ) ) {
				$sql_alterations = '';
			} else {
				$alterations = implode( "', '", array_map( 'esc_sql', $alterations ) );
				if ( ! $alterations ) {
					//misconfiguration, just assume "all"
					$sql_alterations = '';
				} else {
					$sql_alterations = " post_meta_admin_search.meta_key IN ('$alterations') AND ";
				}
			}
			$where = preg_replace(
				"/\(\s*" . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
				"(" . $wpdb->posts . ".post_title LIKE $1) OR ( $sql_alterations post_meta_admin_search.meta_value LIKE $1 )", $where );
		}

		return $where;
	}

	// admin panel
	public static function admin_page_callback() {
		global $wpdb;
		$post_types     = get_post_types( [ 'public' => 1 ], 'objects' );
		unset($post_types['attachment']);
		$settings       = get_option( 'admin_search_meta_options', [] );
		$settings       = $settings ?: [];
		$meta_fields    = array_combine(array_keys($post_types), array_fill(0, count($post_types), []));
		$sql_post_types = implode( "', '", array_map( 'esc_sql', array_keys($post_types) ) );
		$fields         = $wpdb->get_results( sprintf( "SELECT DISTINCT(m.meta_key), p.post_type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type IN ('%s')", $sql_post_types ) );
		foreach ( $fields as $field ) {
			$meta_fields[ $field->post_type ][] = $field->meta_key;
		}

		array_walk( $meta_fields, function ( &$fields ) {
			natcasesort( $fields );
		} );

		if ( isset( $_POST ) && isset( $_POST['settings'] ) ) {
			$new_settings = $_POST['settings'];
			array_walk( $new_settings, function ( &$setting ) {
				$setting['fields'] = array_filter( (array) $setting['fields'] );
			} );
			$new_settings = array_filter( $new_settings );
			update_option( 'admin_search_meta_options', $new_settings );

			print '<script>document.location=' . json_encode( remove_query_arg( '_' ) ) . ';</script>';
			exit;
		}

		?>
        <div class="wrap">
            <h2><?php _e( 'Admin Meta Search', 'rmp_asm' ); ?></h2>
            <p><?php _e( 'Select post-types for which you want to allow meta-searching in WP-Admin.', 'rmp_asm' ); ?></p>
            <form action="<?php print esc_attr( add_query_arg( [ '_' => microtime( true ) ] ) ); ?>" method="post">
				<?php foreach ( $meta_fields as $post_type => $fields ) { ?>
                    <div class="set-wrap">
                        <input id="post-type-<?php print $post_type; ?>"
                               type="checkbox" <?php if ( isset($settings[ $post_type ]) && $settings[ $post_type ]['enabled'] ) { print 'checked="checked"'; } ?>
                               value="<?php print $post_type; ?>" name="settings[<?php print $post_type; ?>][enabled]"/>
                        <label for="post-type-<?php print $post_type; ?>"><?php print $post_types[ $post_type ]->label; ?></label>

                        <fieldset id="fields-for-<?php print $post_type; ?>">
                            <p><?php _e( 'If none selected, all meta will be searched.', 'rmp_asm' ); ?></p>
                            <p><?php if (!$fields) { print sprintf( __( 'There are no posts of type %s yet to determine available meta-keys.', 'rmp_asm' ), $post_types[ $post_type ]->label); } ?></p>
                            <div class="flex">
								<?php foreach ( $fields as $field ) {
									$sane_key = 'f' . md5( $post_type . '-' . $field ); ?>
                                    <div class="item-wrap">
                                        <input id="<?php print $sane_key; ?>"
                                               type="checkbox" <?php if ( isset($settings[ $post_type ]['fields'][ $field ] ) ) { print 'checked="checked"'; } ?>
                                               value="<?php print $field; ?>"
                                               name="settings[<?php print $post_type; ?>][fields][<?php print $field; ?>]"/>
                                        <label for="<?php print $sane_key; ?>"><?php print $field; ?></label>
                                    </div>
								<?php } ?>
                            </div>
                        </fieldset>
                    </div>
				<?php } ?>
                <button class="button button-primary button-large"><?php _e( 'Save', 'rmp_asm' ); ?></button>
            </form>
        </div>
        <style>
            input[type=checkbox]:checked + label + fieldset {
                display: block;
            }

            fieldset {
                display: none;
                border: 1px solid grey;
                padding: 2px
            }

            fieldset .flex {
                display: flex;
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: stretch;
                align-content: flex-start;
            }

            fieldset .flex:after {
                content: '';
                flex: auto
            }

            fieldset .item-wrap {
                display: inline-block;
                width: 20%;
                min-width: 250px;
            }

        </style>
		<?php
	}
}

AdminSearchMeta::getInstance();
