<?php
/*
 * Plugin Name: F1 Multi-Section Reports
 * Version: 0.1
 * Description: Adds a metabox to the edit post screen so you can add children posts.
 * Author: Forum One, Russell Heimlich
 * GitHub Plugin URI: https://github.com/forumone/f1-multi-section-reports
 */

class F1_Multi_Section_Reports {

	private $version = 0.1; // This should match the plugin version declared in the header ^^^

	/**
	*	We don't use __construct() becuase it is harder to debug ???
	*/
	public function __construct() {}

	/**
	 * Setup hooks for actions and filters.
	 */
	public function setup() {
		// Enqueue the CSS needed for the meta box
		add_action( 'admin_print_styles-post.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-post-new.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_init', array( $this, 'register_admin_scripts_and_styles' ) );

		// Meta box
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );

		add_action( 'save_post', array( $this, 'save_multi_section_report' ) );

		// Handle AJAX requests
		add_action( 'wp_ajax_multi_section_search', array( $this, 'multi_section_search_ajax_callback' ) );
		add_action('wp_ajax_multi_section_date', array( $this, 'multi_section_date_ajax_callback' ) );

		// Filters
		add_filter(' body_class', array( $this, 'add_multi_section_report_classes' ) );
		add_filter( 'the_title', array( $this, 'visually_indicate_child_posts' ) );
	}

	/**
	 * Register admin CSS and JavaScript early on so we can enqueue it later when needed.
	 */
	public function register_admin_scripts_and_styles() {
		$path = WP_PLUGIN_URL . '/f1-multi-section-reports';

		wp_register_script( 'f1-multi-section-admin-js', $path . '/f1-multi-section-admin.js', 'jquery-ui-sortable', $this->version, true );
		wp_register_style( 'f1-multi-section-admin-css', $path . '/f1-multi-section-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Enqueue the admin CSS file if appropriate
	 */
	public function enqueue_admin_styles() {
		global $current_screen;
		if( !in_array( get_post_type(), $this->get_allowed_post_types() ) ) {
			return;
		}
		if( $current_screen->id != 'post' ) {
			return;
		}
		wp_enqueue_style( 'f1-multi-section-admin-css' );
	}

	/**
	 * Setup the meta box if the post type is supposed to support multi section reports.
	 */
	public function register_meta_boxes() {
		global $typenow, $current_screen;
		$post = get_post();

		// Figure out what the post_type is via http://themergency.com/wordpress-tip-get-post-type-in-admin/
		$post_type = null;

		// We have a post so we can just get the post type from that
		if( $post && isset( $post->post_type ) ) {
			$post_type = $post->post_type;
		}

		// Check the global $typenow - set in admin.php
		elseif( $typenow ) {
			$post_type = $typenow;
		}

		// Check the global $current_screen object - set in sceen.php
		elseif( $current_screen && $current_screen->post_type ) {
			$post_type = $current_screen->post_type;
		}

		// Lastly check the post_type querystring
		elseif( isset( $_REQUEST['post_type'] ) ) {
			$post_type = sanitize_key( $_REQUEST['post_type'] );
		}


		if( in_array( $post_type, $this->get_allowed_post_types() ) ) {
			wp_enqueue_script( 'f1-multi-section-admin-js' );
			add_meta_box( 'f1-multi-section-report', 'Multi-Section Report', array( $this, 'admin_meta_box_callback'), $post_type, 'normal', 'high' );
		}

	}

	/**
	 * Renders the HTML for the meta box
	 * @param  Object $post The post being edited
	 */
	public function admin_meta_box_callback( $post ) {
		$post = get_post( $post );

		if( $post->post_parent != 0 ) {

			$parent = get_page( $post->post_parent );
			$parent_edit_link = esc_url( get_edit_post_link( $parent->ID ) );
			?>
			This post is a child of <a href="<?php echo $parent_edit_link;?>"><?php echo $parent->post_title;?></a>
			<?php

		} else {

			$section_ids = array();

			$args = array(
				'numberposts' => -1,
				'orderby' => 'menu_order',
				'order' => 'ASC',
				'post_parent' => $post->ID,
				'post_type' => $this->get_allowed_post_types(),
				'post_status' => array( 'publish', 'draft', 'future' ),
			);

			if ( $sections = get_posts( $args ) ) {
			?>
				<p>Children Posts <em>(Drag to Reorder)</em></p>
				<ul id="f1-multi-section-children">
				<?php
				foreach( $sections as $section ) {
					$section_ids[] = $section->ID;
					$section_edit_link = get_edit_post_link( $section->ID );
					$section_permalink = get_permalink( $section->ID );
				?>
					<li><a href="<?php echo esc_url( $section_edit_link );?>" id="post-<?php echo $section->ID;?>"><?php echo $section->post_title;?></a>
						<span class="row-actions">
							<a href="<?php echo esc_url( $section_permalink );?>" target="_blank" class="view">View</a> | <a href="#<?php echo $section->ID; ?>" class="trash">Remove</a>
						</span>
					</li>
					<?php
				}
				?>
				</ul>
				<input type="hidden" value="<?php echo esc_attr( implode(',', $section_ids ) );?>" name="selectedIDs" id="selectedIDs">
				<?php

			} else {

				$nonce= wp_create_nonce('multi_section_report_builder');
				?>
				<ul id="f1-multi-section-children">
					<li id="no-children">There are no children.</li>
				</ul>
				<input type="hidden" value="" name="selectedIDs" id="f1-selectedIDs">
				<?php
			}
			?>

			<input type="hidden" value="" name="removedIDs" id="f1-removedIDs">
			<input type="hidden" value="0" name="do_multi_section_save" id="f1-do-multi-section-save">

			<?php // Used to feed paths to f1-multi-section-admin.js ?>
			<input type="hidden" id="get_admin_url" value="<?php echo esc_attr( get_admin_url() );?>">
			<input type="hidden" id="get_home_url" value="<?php echo esc_attr( get_home_url() );?>">
			<input type="hidden" id="current_id" value="<?php echo intval( $post->ID );?>">

			<?php // ID naming here is important. WordPress admin JS expects a container with the id = tax-whatever. It will then look for a child element with an id = whatever-tabs to make tabbing work. I'm too lazy to implement my own tabs in a saner way...  ?>
			<div id="tax-f1-multi-section" class="categorydiv">

				<ul id="f1-multi-section-tabs" class="category-tabs">
					<li class="tabs"><a href="#f1-multi-section-recent">Recent</a></li>
					<li><a href="#f1-multi-section-search">Search</a></li>
					<li id="f1-multi-section-date-tab"><a href="#f1-multi-section-date">Date</a></li>
				</ul>

				<div id="f1-multi-section-recent" class="tabs-panel">

					<ul class="categorychecklist form-no-clear">
					<?php
					$args = array(
						'numberposts' => '8',
						'orderby' => 'post_date',
						'order' => 'DESC',
						'post_type' => $this->get_allowed_post_types(),
						'exclude' => $post->ID,
						'post_status' => array( 'publish', 'draft', 'future'),
					);
					$posts = get_posts( $args );
					foreach( $posts as $p ) {
						$checked = '';
						if( $section_ids && in_array($p->ID, $section_ids) ) {
							$checked = 'checked';
						}
						$p_permalink = get_permalink( $p->ID );
						?>
						<li class="<?php echo esc_attr( $p->post_status );?>">
							<label class="selectit" for="recent-post-<?php echo intval( $p->ID );?>">
							<input type="checkbox" name="post-<?php echo intval( $p->ID );?>" id="recent-post-<?php echo intval( $p->ID );?>" value="<?php echo intval( $p->ID );?>" <?php echo $checked;?>>
								<?php echo $p->post_title;?>
							</label>
							<span class="row-actions">
								<a href="<?php echo esc_url( $p_permalink );?>" target="_blank" class="view">View</a>
							</span>
						</li>
						<?php
					}
					?>
					</ul>

				</div>

				<div id="f1-multi-section-search" class="tabs-panel" style="display:none;">
					<form>
						<input type="search">
						<img class="waiting" src="<?php echo esc_url( get_admin_url() );?>/images/wpspin_light.gif" style="display: none;">
					</form>
					<ul class="ajax_result"></ul>
				</div>

				<div id="f1-multi-section-date" class="tabs-panel" style="display:none;">
					<form>
						<input type="date" value="<?php echo date('Y-m-d', strtotime($post->post_date));?>">
						<img class="waiting" src="<?php echo esc_url( get_admin_url() );?>/images/wpspin_light.gif" style="display: none;">
					</form>
					<ul class="ajax_date_result"></ul>
				</div>

			</div>
			<?php
		}
	}

	/**
	 * Save meta box info
	 * @param  int $post_id ID of the post to save the data to
	 */
	public function save_multi_section_report( $post_id ) {
		global $wpdb;
		$post_id = intval( $post_id );

		if( !isset( $_POST['do_multi_section_save'] ) || $_POST['do_multi_section_save'] != 1 ) {
			return $post_id;
		}
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			return $post_id;
		}
		if( defined('DOING_AJAX') && DOING_AJAX ) {
			return $post_id;
		}

		$selected_ids = sanitize_text_field( $_POST['selectedIDs'] );
		$update_ids = explode( ',', $selected_ids ); //selectedIDs = '1,2,3' etc.
		$ids = $_POST['selectedIDs'];

		$sql = "UPDATE " . $wpdb->posts . " ";
		$sql .= "SET `post_parent` = " . $post_id . ", ";
		$sql .= "`menu_order` = CASE `ID` ";
		foreach( $update_ids as $order => $id ) {
			$sql .= sprintf("WHEN %d THEN %d ", intval( $id ), intval( $order ) );
		}
		$sql .= "END ";
		$sql .= "WHERE `ID` IN (" . $ids . ")";

		if( !empty( $update_ids ) ) {
			$wpdb->query( $sql );
		}

		$ids = sanitize_text_field( $_POST['removedIDs'] ); //removedIDs = '1,2,3' etc.

		$sql = "UPDATE " . $wpdb->posts . " ";
		$sql .= "SET `post_parent` = 0, ";
		$sql .= "`menu_order` = 0 ";
		$sql .= "WHERE `ID` IN (" . $ids . ")";

		if( $ids ) {
			$wpdb->query( $sql );
		}
	}

	public function multi_section_search_ajax_callback() {
		add_filter('posts_search', array( $this, 'only_search_post_titles' ) );
		$selected_ids = sanitize_text_field( $_REQUEST['selectedIds'] );
		$selected_ids = explode( ',', $selected_ids );
		$q = sanitize_text_field( $_REQUEST['q'] );
		$args = array(
			'posts_per_page' => 10,
			'post_type' => $this->get_allowed_post_types(),
			's' => $q,
			'post_status' => array( 'publish', 'draft', 'future' ),

			// For performance...
			'no_found_rows' => true, // Useful when pagination is not needed
			'update_post_meta_cache' => false, // Useful when post meta will not be utilized
			'update_post_term_cache' => false, // Useful when taxonomy terms will not be utilized
		);
		if( $current_id = intval( $_REQUEST['currentID'] ) ) {
			$args['post__not_in'] = array( $current_id );
		}
		$posts = get_posts( $args );
		if(!$posts) {
			?>
			<li>No posts found with <em><?php echo  $_REQUEST['q']; ?></em> in the title.</li>
			<?php
		} else {
			foreach( $posts as $p ) {
				$checked = '';
				if( in_array( $p->ID, $selected_ids ) ) { $checked = 'checked'; }
				$post_date = get_the_date( '', $p->ID ) . ' ' . get_the_time( '', $p->ID );
				?>

				<li class="<?php echo $p->post_status;?>">
					<label class="selectit" for="search-post-<?php echo $p->ID;?>">
						<input type="checkbox" name="post-<?php echo $p->ID;?>" id="search-post-<?php echo $p->ID;?>" value="<?php echo $p->ID;?>" <?php echo $checked;?>>
						<?php echo $p->post_title;?> (<?php echo $post_date; ?>)
					</label>
						<span class="row-actions"><a href="<?php echo esc_url( get_permalink( $p->ID ) );?>" target="_blank" class="view">View</a></span>
				</li>
				<?php
			}
		}
		die(); // this is required to return a proper result
	}

	public function only_search_post_titles( $where ) {
		error_log( $where );
		$parts = explode( 'OR', $where ); // Find the OR in the sql query and return everything before OR.
		return rtrim( $parts[0] ) . '))';
	}

	public function multi_section_date_ajax_callback() {
		$selectedIDs = explode(',', $_REQUEST['selectedIds'] );
		$date = strtotime( $_REQUEST['date'] );
		$args = array(
			'posts_per_page' => 20,
			'post_type' => $this->get_allowed_post_types(),
			'post_status' => array( 'publish', 'draft', 'future' ),
			'year' => date('Y', $date),
			'monthnum' => date('n', $date),
			'day' => date('j', $date),

			// For performance...
			'no_found_rows' => true, // Useful when pagination is not needed
			'update_post_meta_cache' => false, // Useful when post meta will not be utilized
			'update_post_term_cache' => false, // Useful when taxonomy terms will not be utilized
		);
		if( $current_id = intval( $_REQUEST['currentID'] ) ) {
			$args['post__not_in'] = array( $current_id );
		}
		$posts = get_posts( $args );
		if( !$posts ) {
			?>
			<li>No posts found for <em><?php echo  $_REQUEST['date']; ?></em>.</li>
			<?php
		} else {
			foreach( $posts as $p ) {
				$checked = '';
				if( in_array($p->ID, $selectedIDs) ) { $checked = 'checked'; }
				?>

				<li class="<?php echo $p->post_status;?>">
					<label class="selectit" for="date-post-<?php echo $p->ID;?>">
						<input type="checkbox" name="post-<?php echo $p->ID;?>" id="date-post-<?php echo $p->ID;?>" value="<?php echo $p->ID;?>" <?php echo $checked;?>> <?php echo $p->post_title;?> </label>
						<span class="row-actions"><a href="<?php echo get_permalink( $p->ID );?>" target="_blank" class="view">View</a></span>
				</li>

				<?php
			}
		}
		die(); // this is required to return a proper result
	}

	public function add_multi_section_report_classes( $class ) {
		/*
		DEAL WITH THIS LATER...

		$post = get_post();
		$whitelisted_post_types = array('post', 'attachment', 'methodology');
		if( is_single() && in_array( get_post_type(), $whitelisted_post_types ) ) {
			$multisection = f1_get_multi_section_report();
			if( $multisection ) {
				$class[] = 'multi-section-report';
			}
			if( $multisection->parent_section ) {
				$class[] = 'multi-section-parent';
			}
			if( $multisection->child_section ) {
				$class[] = 'multi-section-child';
			}
		}
		*/

		return $class;
	}

	/*
	 *
	 * Modify the post title if it's a child post in the admin view.
	 *
	 */
	public function visually_indicate_child_posts( $title ) {
		$post = get_post();

		// Not interested if it's not the admin screen...
		if( !is_admin() ) {
			return $title;
		}

		// Not interested if we're not looking at the edit.php screen...
		$screen = get_current_screen();
		if( !$screen || $screen->parent_base != 'edit' ) {
			return $title;
		}

		// We're white-listing valid post_types to apply this to.
		if( !in_array( $post->post_type, $this->get_allowed_post_types() ) ) {
			return $title;
		}

		// Hierarchical post types already do this...
		if( is_post_type_hierarchical( $post->post_type ) ) {
			return $title;
		}

		//Add a m dash before the title...
		if( $post->post_parent != 0 ) {
			$title = '&mdash; ' . $post->post_title;
		}

		return $title;
	}

	/**
	 * Helper function to get the allowed post types that should support multi-section reports
	 * @return array An array of allowed post type slugs
	 */
	public function get_allowed_post_types() {
		$allowed_post_types = apply_filters( 'f1_multi_section_reports_post_types', array( 'post' ) );
		if( !array( $allowed_post_types ) ) {
			$allowed_post_types = array( $allowed_post_types );
		}

		return $allowed_post_types;
	}
}
global $f1_multi_section_reports;
$f1_multi_section_reports = new F1_Multi_Section_Reports();
$f1_multi_section_reports->setup();


/*
 *
 * Helper Functions
 *
 */

function f1_is_multi_section_report() {
	if( !wp_cache_get( 'multi-section-report' ) ) {
		f1_get_multi_section_report();
	}

	if( wp_cache_get( 'multi-section-report' ) ) {
		return true;
	}

	return false;
}

function f1_get_multi_section_report() {
	global $f1_multi_section_reports;
	$post = get_post();

	if( $cached = wp_cache_get( 'multi-section-report' ) ) {
		return $cached;
	}

	if( !is_singular() && !is_admin() ) {
		wp_cache_set( 'multi-section-report', false );
		return false;
	}

	$parent = $post;
	while($parent->post_parent != 0) {
		$parent = get_page( $parent->post_parent );
	}

	$args = array(
		'numberposts' => 101,
		'orderby' => 'menu_order',
		'order' => 'ASC',
		'post_parent' => $parent->ID,
		'post_type' => $f1_multi_section_reports->get_allowed_post_types(),
	);

	if( is_preview() || $parent->post_status == 'future' ) {
		$args['post_status'] = array('publish', 'pending', 'draft', 'future', 'inherit');
	}

	// Todo: Rewrite this as a wp_query to avoid extra SQL queries
	$posts = get_posts( $args );
	if( count( $posts ) < 1 ) {
		wp_cache_set( 'multi-section-report', false );
		return false;
	}

	$sections = array_merge( array($parent), $posts );
	$current_section = 0;
	foreach($sections as $section) {
		if($post->ID == $section->ID) {
			break;
		}
		$current_section++;
	}

	$child_section = true;
	$parent_section = false;
	if( $current_section === 0 ) {
		$child_section = false;
		$parent_section = true;
	}

	$multi_section_report = (object) array(
		'current_section' => $current_section,
		'parent_section' => $parent_section,
		'child_section' => $child_section,
		'sections' => $sections
	);

	wp_cache_set( 'multi-section-report', $multi_section_report );

	return $multi_section_report;
}
