<?php
/**
 * @package Fieldmanager_Context
 */

/**
 * Use fieldmanager to create meta boxes on
 * @package Fieldmanager_Datasource
 */
class Fieldmanager_Context_QuickEdit extends Fieldmanager_Context {

	/**
	 * @var string
	 * Title of QuickEdit box; also used for the column title unless $column_title is specified.
	 */
	public $title = '';

	/**
	 * @var string
	 * Override $title for the column in the list of posts
	 */
	public $column_title = '';

	/**
	 * @var callback
	 * QuickEdit fields are tied to custom columns in the list of posts. This callback should return a value to
	 * display in a custom column.
	 */
	public $column_display_callback = null;

	/**
	 * @var string[]
	 * What post types to render this Quickedit form
	 */
	public $post_types = array();

	/**
	 * @var Fieldmanager_Group
	 * Base field
	 */
	public $fm = '';

	/**
	 * Callbacks for manipulating data.
	 * @var array
	 */
	public $data_callbacks = array(
		'get'    => "get_post_meta",
		'add'    => "add_post_meta",
		'update' => "update_post_meta",
		'delete' => "delete_post_meta",
	);

	/**
	 * Add a context to a fieldmanager
	 * @param string $title
	 * @param string|string[] $post_types
	 * @param callback $column_not_empty_callback
	 * @param callback $column_empty_callback
	 * @param Fieldmanager_Field $fm
	 */
	public function __construct( $title, $post_types, $column_display_callback, $column_title = '', $fm = Null ) {

		if ( !fm_match_context( 'quickedit' ) ) return; // make sure we only load up our JS if we're in a quickedit form.

		if ( FM_DEBUG && !is_callable( $column_display_callback ) ) {
			throw new FM_Developer_Exception( esc_html__( 'You must set a valid column display callback.', 'fieldmanager' ) );
		}

		// Populate the list of post types for which to add this meta box with the given settings
		if ( !is_array( $post_types ) ) $post_types = array( $post_types );

		$this->post_types = $post_types;
		$this->title = $title;
		$this->column_title = !empty( $column_title ) ? $column_title : $title;
		$this->column_display_callback = $column_display_callback;
		$this->fm = $fm;

		if ( is_callable( $column_display_callback ) ) {
			foreach ( $post_types as $post_type ) {
				add_action( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_custom_columns' ) );
			}
			add_action( 'manage_posts_custom_column', array( $this, 'manage_custom_columns' ), 10, 2 );
		}

		add_action( 'quick_edit_custom_box', array( $this, 'add_quickedit_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_fields_for_quickedit' ) );
		add_action( 'wp_ajax_fm_quickedit_render', array( $this, 'render_ajax_form' ), 10, 2 );

		$post_type = !isset( $_GET['post_type'] ) ? 'post' : sanitize_text_field( $_GET['post_type'] );

		if ( in_array( $post_type, $this->post_types ) ) {
			fm_add_script( 'quickedit-js', 'js/fieldmanager-quickedit.js' );
		}
	}

	/**
	 * manage_{$post_type}_posts_columns callback, as QuickEdit boxes only work on custom columns.
	 * @param array $columns
	 * @return void
	 */
	function add_custom_columns( $columns ) {
		$columns[$this->fm->name] = $this->column_title;
		return $columns;
	}

	/**
	 * manage_posts_custom_column callback
	 * @param string $column_name
	 * @param int $post_id
	 * @return void
	 */
	public function manage_custom_columns( $column_name, $post_id ) {
		if ( $column_name != $this->fm->name ) {
			return;
		}
		$data = get_post_meta( $post_id, $this->fm->name, true );
		$column_text = call_user_func( $this->column_display_callback, $post_id, $data );
		echo $column_text;
	}

	/**
	 * quick_edit_custom_box callback. Renders the QuickEdit box.
	 * Renders with blank values here since QuickEdit boxes cannot access to the WP post_id.
	 * The values will be populated by an ajax-fetched form later (see $this->render_ajax_form() ).
	 * @param string $column_name
	 * @param string $post_type
	 * @return void
	 */
	public function add_quickedit_box( $column_name, $post_type, $values = array() ) {
		if ( $column_name != $this->fm->name ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-left fm-quickedit" id="fm-quickedit-<?php echo esc_attr( $column_name ); ?>" data-fm-post-type="<?php echo esc_attr( $post_type ); ?>">
			<div class="inline-edit-col">
				<?php $this->_render_field( array( 'data' => $values ) ); ?>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Callback for wp_ajax_fm_quickedit_render.
	 * Renders a form with pre-filled values to replace the one generated by $this->add_quickedit_box().
	 * @return string
	 */
	public function render_ajax_form() {
		if ( ! isset( $_GET['action'], $_GET['post_id'], $_GET['column_name'] ) ) {
			return;
		}

		if ( $_GET['action'] != 'fm_quickedit_render' ) {
			return;
		}

		$column_name = sanitize_text_field( $_GET['column_name'] );
		$post_id = intval( $_GET['post_id'] );

		if ( ! $post_id || $column_name != $this->fm->name ) {
			return;
		}

		$this->fm->data_type = 'post';
		$this->fm->data_id = $post_id;
		$post_type = get_post_type( $post_id );

		return $this->add_quickedit_box( $column_name, $post_type, $this->_load() );
	}

	/**
	 * Takes $_POST data and saves it to, calling save_to_post_meta() once validation is passed
	 * When using Fieldmanager as an API, do not call this function directly, call save_to_post_meta()
	 * @param int $post_id
	 * @return void
	 */
	public function save_fields_for_quickedit( $post_id ) {
		// Make sure this field is attached to the post type being saved.
		if ( !isset( $_POST['post_type'] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || $_POST['action'] != 'inline-save' ) {
			return;
		}

		$use_this_post_type = false;
		foreach ( $this->post_types as $type ) {
			if ( $type == $_POST['post_type'] ) {
				$use_this_post_type = true;
				break;
			}
		}
		if ( !$use_this_post_type )  {
			return;
		}

		// Ensure that the nonce is set and valid
		if ( ! $this->_is_valid_nonce() ) {
			return;
		}

		// Make sure the current user can save this post
		if( $_POST['post_type'] == 'post' ) {
			if( !current_user_can( 'edit_post', $post_id ) ) {
				$this->fm->_unauthorized_access( __( 'User cannot edit this post', 'fieldmanager' ) );
				return;
			}
		}

		$this->save_to_post_meta( $post_id );
	}

	/**
	 * Helper to save an array of data to post meta
	 * @param int $post_id
	 * @param array $data
	 * @return void
	 */
	public function save_to_post_meta( $post_id, $data = null ) {
		$this->fm->data_id = $post_id;
		$this->fm->data_type = 'post';

		$this->_save( $data );
	}

}