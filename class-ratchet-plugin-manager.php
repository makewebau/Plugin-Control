<?php
class ratchet_Plugin_Manager {

		private $args = array();
		private $package;
		private $plugins = array();
		private $admin_screen_base;
		private $plugin_file;

		public function __construct( $plugins, $args = [] ) {

			$this->args = $args;
			$backtrace = debug_backtrace();
			$plugin_file = $backtrace[0]["file"];
			$this->set_package();
			$this->set_plugins( $plugins );
			$this->set_args( $args );
			$this->set_notices();
			
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'add_assets' ) );
			add_filter( 'views_plugins', array( $this, 'add_plugins_view' ) );
			add_filter( 'views_plugin-install', array( $this, 'add_install_view' ) );
			add_action( 'wp_ajax_plugin-manager-row-refresh', array( $this, 'row_refresh' ) );
			add_action( 'current_screen', array( $this, 'request' ) );

		}

		private function set_package() {

			$this->package = array(
				'directory' => dirname( __FILE__ ),
			);
			
			if ( $this->args['plugin_file'] ) {

				$plugin_file = $this->args['plugin_file'];

				$plugin_slug = explode( '/', plugin_basename( $plugin_file ) );

				$this->package['slug'] = $plugin_slug[0];

				$plugin = get_file_data( $plugin_file, array(
					'Name' => 'Plugin Name'
				), 'plugin' );

				$this->package['name'] = $plugin['Name'];

				$plugin_dir = plugin_dir_path( $plugin_file );

				$dropin_path = str_replace( $plugin_dir, '', $this->package['directory'] );

				$this->package['url'] = plugin_dir_url( $plugin_file ) . untrailingslashit( $dropin_path );

			} else {

				if ( ! empty( $this->args['child_theme'] ) ) {
					$theme = wp_get_theme( get_stylesheet() );
				} else {
					$theme = wp_get_theme( get_template() );
				}

				$this->package['slug'] = $theme->get_stylesheet();

				$this->package['name'] = $theme->get( 'Name' );

				$theme_dir = $theme->get_stylesheet_directory();

				$dropin_path = str_replace( $theme_dir, '', $this->package['directory'] );

				$this->package['url'] = $theme->get_stylesheet_directory_uri() . untrailingslashit( $dropin_path );

			}

		}

		private function set_plugins( $plugins ) {

			require_once( $this->package['directory'] . '/class-ratchet-plugins.php' );

			// Setup plugins.
			$this->plugins = new ratchet_Plugins( $plugins, $this );

		}

		private function set_args( $args = array() ) {

			$this->args = wp_parse_args( $args, array(
				'page_title'           => __( 'Suggested Plugins', 'ratchet' ),
				// translators: 1: name of theme
				'views_title'          => sprintf( __( 'Suggested by %s', 'ratchet' ), $this->package['name'] ),
				// translators: 1: name of theme
				'tab_title'            => sprintf( __( 'Suggested by %s', 'ratchet' ), $this->package['name'] ),
				// translators: 1: name of theme
				'extended_title'       => sprintf( __( 'Suggested Plugins by %s', 'ratchet' ), $this->package['name'] ),
				'menu_title'           => '', // Takes on page_title, when left blank.
				'parent_slug'          => 'plugins.php',
				'menu_slug'            => 'suggested-plugins',
				'capability'           => 'install_plugins',
				'nag_action'           => __( 'Manage suggested plugins', 'ratchet' ),
				'nag_dismiss'          => __( 'Dismiss this notice', 'ratchet' ),
				// translators: 1: name of theme
				'nag_update'           => __( 'Not all of your active, suggested plugins are compatible with %s.', 'ratchet' ),
				// translators: 1: name of theme, 2: number of suggested plugins
				'nag_install_single'   => __( '%1$s suggests installing %2$s plugin.', 'ratchet' ),
				// translators: 1: name of theme, 2: number of suggested plugins
				'nag_install_multiple' => __( '%1$s suggests installing %2$s plugins.', 'ratchet' ),
				'child_theme'          => false,
				'plugin_file'          => 'donkey',
			));

			if ( ! $this->args['menu_title'] ) {
				$this->args['menu_title'] = $this->args['page_title'];
			}

		}

		private function set_notices() {

			require_once( $this->package['directory'] . '/class-ratchet-plugin-notices.php' );

			// Setup notices.
			$args = array(
				'package_name'         => $this->package['name'],
				'package_url'          => $this->package['url'],
				'admin_url'            => $this->get_admin_url(),
				'nag_action'           => $this->args['nag_action'],
				'nag_dismiss'          => $this->args['nag_dismiss'],
				'nag_update'           => $this->args['nag_update'],
				'nag_install_single'   => $this->args['nag_install_single'],
				'nag_install_multiple' => $this->args['nag_install_multiple'],
			);

			$notices = new ratchet_Plugin_Notices( $args, $this, $this->plugins );

		}

		public function add_page() {

			$this->admin_screen_base = add_submenu_page(
				$this->args['parent_slug'],
				$this->args['page_title'],
				$this->args['menu_title'],
				$this->args['capability'],
				$this->args['menu_slug'],
				array( $this, 'display_page' )
			);

		}

		public function add_assets() {

			if ( ! $this->is_admin_screen() ) {
				return;
			}

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_script( 'updates' );

			wp_enqueue_script(
				'ratchet-plugin-manager',
				esc_url( $this->package['url'] . "/assets/js/plugin-manager$suffix.js" ),
				array( 'jquery', 'updates' )
			);

			wp_localize_script(
				'ratchet-plugin-manager',
				'pluginManagerSettings',
				array(
					'thirdParty'   => __( 'Only plugins from wordpress.org can be installed directly here.', 'ratchet' ),
					'notInstalled' => __( 'Plugin update skipped because it is not installed.', 'ratchet' ),
				)
			);

			wp_enqueue_style(
				'ratchet-plugin-manager',
				esc_url( $this->package['url'] . "/assets/css/plugin-manager$suffix.css" )
			);

		}

		public function display_page() {

			$plugins = $this->plugins->get();

			settings_errors( 'plugin-manager' );

			?>
			<div id="suggested-plugins" class="wrap">

				<h1><?php echo esc_html( $this->args['page_title'] ); ?></h1>

				<?php if ( $plugins ) : ?>

					<form method="post" id="bulk-action-form" data-namespace="ratchet">

						<?php $this->display_table_nav( 'top' ); ?>

						<table class="wp-list-table plugins widefat">

							<?php $this->display_table_header( 'thead' ); ?>

							<tbody id="the-list" data-wp-lists="list:plugin">

								<?php foreach ( $plugins as $plugin ) : ?>

									<?php $this->display_table_row( $plugin ); ?>

								<?php endforeach; ?>

							</tbody>

							<?php $this->display_table_header( 'tfoot' ); ?>

						</table>

						<?php $this->display_table_nav( 'bottom' ); ?>

					</form>

				<?php else : ?>

					<p><?php esc_html_e( 'No suggested plugins given.', 'ratchet' ); ?></p>

				<?php endif; ?>

			</div><!-- .wrap -->
			<?php
		}

		private function display_table_nav( $which ) {

			$actions = array(
				'install-selected'    => __( 'Install', 'ratchet' ),
				'activate-selected'   => __( 'Activate', 'ratchet' ),
				'deactivate-selected' => __( 'Deactivate', 'ratchet' ),
				'update-selected'     => __( 'Update', 'ratchet' ),
				'delete-selected'     => __( 'Delete', 'ratchet' ),
			);

			if ( 'top' === $which ) {
				wp_nonce_field( 'bulk-plugins' );
			}

			?>
			<div class="tablenav">
				<div class="actions bulkactions">

					<label for="bulk-action-selector-<?php echo esc_attr( $which ); ?>" class="screen-reader-text">
						<?php esc_html_e( 'Select bulk action', 'ratchet' ); ?>
					</label>

					<select name="action-<?php echo $which; ?>" id="bulk-action-selector-<?php echo esc_attr( $which ); ?>">

						<option value="-1"><?php esc_html_e( 'Bulk Actions', 'ratchet' ); ?></option>

						<?php foreach ( $actions as $name => $title ) : ?>

							<option value="<?php echo $name; ?>"><?php echo $title; ?></option>

						<?php endforeach; ?>

					</select>

					<?php
					submit_button( __( 'Apply', 'ratchet' ), 'action', '', false,
						array(
							'id' => "do-action-$which",
						)
					);
					?>

				</div>
			</div>
			<?php
		}

		private function display_table_header( $tag ) {

			$id = 'tfoot' === $tag ? '2' : '1';

			?>
			<<?php echo $tag; ?>>

				<tr>

					<td id="cb" class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="cb-select-all-<?php echo $id; ?>">
							<?php esc_html_e( 'Select All', 'ratchet' ); ?>
						</label>
						<input id="cb-select-all-<?php echo $id; ?>" type="checkbox">
					</td>

					<th scope="col" class="manage-column column-name column-primary">
						<?php esc_html_e( 'Plugin', 'ratchet' ); ?>
					</th>

					<th scope="col" class="manage-column column-compatible-version">
						<?php esc_html_e( 'Compatible Version', 'ratchet' ); ?>
					</th>

					<th scope="col" class="manage-column column-installed-version">
						<?php esc_html_e( 'Installed Version', 'ratchet' ); ?>
					</th>

					<th scope="col" class="manage-column column-status">
						<?php esc_html_e( 'Status', 'ratchet' ); ?>
					</th>

				</tr>

			</<?php echo $tag; ?>>
			<?php

		}

		private function display_table_row( $plugin ) {

			$class = array( $plugin['status'] );

			if ( 'not-installed' === $plugin['status'] ) {
				$class[] = 'inactive'; // Better implements default WP_Plugin_Table styling.
			}

			if ( 'incompatible' === $plugin['status'] ) {
				$class[] = 'active'; // Better implements default WP_Plugin_Table styling.
			}

			if ( ! empty( $plugin['row-class'] ) ) {
				$class = array_merge( $class, $plugin['row-class'] );
			}

			$class = implode( ' ', $class );

			?>
			<tr class="<?php echo esc_attr( $class ); ?>" data-slug="<?php echo esc_attr( $plugin['slug'] ); ?>" data-plugin="<?php echo esc_attr( $plugin['file'] ); ?>" data-status="<?php echo esc_attr( $plugin['status'] ); ?>" data-source="<?php echo esc_attr( $this->get_plugin_source( $plugin ) ); ?>">

				<th scope="row" class="check-column">
					<label class="screen-reader-text" for="">
						<?php
						/* translators: 1: placeholder is name of plugin */
						printf( __( 'Select %s', 'ratchet' ), $plugin['name'] );
						?>
					</label>
					<input type="checkbox" name="checked[]" value="<?php echo $plugin['slug']; ?>">
				</th>

				<td class="plugin-title column-primary">

					<?php if ( $plugin['name'] ) : ?>
						<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
					<?php endif; ?>

					<div class="row-actions visible">
						<?php $this->display_actions( $plugin ); ?>
					</div>

				</td>

				<td class="column-suggested-version">
					<?php if ( isset( $plugin['version'] ) ) : ?>
						<?php echo esc_html( $plugin['version'] ); ?>
					<?php endif; ?>
				</td>

				<td class="column-installed-version">
					<?php if ( $plugin['current_version'] ) : ?>
						<?php echo esc_html( $plugin['current_version'] ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Not Installed', 'ratchet' ); ?>
					<?php endif; ?>
				</td>

				<td class="column-status">
					<?php $this->display_status( $plugin ); ?>
				</td>

			</tr>

			<?php if ( ! empty( $plugin['notice'] ) ) : ?>

				<tr class="<?php echo esc_attr( $class ); ?> row-notice <?php echo esc_attr( $plugin['slug'] ); ?>-notice">

					<td colspan="5" class="plugin-update">
						<div class="update-message notice inline notice-alt <?php echo esc_attr( $plugin['notice']['class'] ); ?>">
							<p><?php echo wp_unslash( $plugin['notice']['message'] ); ?></p>
						</div>
					</td>

				</tr>

			<?php endif; ?>

			<?php

		}

		private function display_actions( $plugin ) {

			$is_wp = false !== strpos( $plugin['url'], 'wordpress.org' );

			$actions = array();

			if ( $is_wp || 'not-installed' !== $plugin['status'] ) {

				$actions['details'] = array(
					'url'    => $plugin['url'],
					'text'   => __( 'Details', 'ratchet' ),
					// translators: 1: name of plugin
					'label'  => sprintf( __( 'More Information about %s', 'ratchet' ), $plugin['name'] ),
					'target' => '_blank',
					'nonce'  => null,
				);

			}

			if ( 'not-installed' === $plugin['status'] ) {

				if ( $is_wp ) {

					$url = add_query_arg(
						array(
							'action'   => 'install-plugin',
							'plugin'   => urlencode( $plugin['slug'] ),
							'_wpnonce' => wp_create_nonce( 'install-plugin_' . $plugin['slug'] ), // Formatted for WP's update.php.
						),
						self_admin_url( 'update.php' )
					);

					$actions['install'] = array(
						'url'    => $url,
						'text'   => __( 'Install', 'ratchet' ),
						// translators: 1: name of plugin
						'label'  => sprintf( __( 'Install %s', 'ratchet' ), $plugin['name'] ),
						'target' => '_self',
						'nonce'  => null, // No nonce needed, using wp.updates.ajaxNonce.
					);

				} else {

					$actions['install'] = array(
						'url'    => $plugin['url'],
						'text'   => __( 'Get Plugin', 'ratchet' ),
						// translators: 1: name of plugin
						'label'  => sprintf( __( 'Install %s', 'ratchet' ), $plugin['name'] ),
						'target' => '_blank',
						'nonce'  => null,
					);

				}
			}

			// Add "Activate" or "Deactivate" link.
			if ( 'inactive' === $plugin['status'] ) {

				$url = add_query_arg(
					array(
						'action'   => 'activate',
						'plugin'   => urlencode( $plugin['slug'] ),
						'_wpnonce' => wp_create_nonce( 'plugin-request_' . $plugin['file'] ),
					),
					$this->get_admin_url()
				);

				$actions['activate'] = array(
					'url'    => $url,
					'text'   => __( 'Activate', 'ratchet' ),
					// translators: 1: name of plugin
					'label'  => sprintf( __( 'Activate %s', 'ratchet' ), $plugin['name'] ),
					'target' => '_self',
				);

			} elseif ( 'active' === $plugin['status'] || 'incompatible' === $plugin['status'] ) {

				$url = add_query_arg(
					array(
						'action'   => 'deactivate',
						'plugin'   => urlencode( $plugin['slug'] ),
						'_wpnonce' => wp_create_nonce( 'plugin-request_' . $plugin['file'] ),
					),
					$this->get_admin_url()
				);

				$actions['deactivate'] = array(
					'url'    => $url,
					'text'   => __( 'Deactivate', 'ratchet' ),
					// translators: 1: name of plugin
					'label'  => sprintf( __( 'Deactivate %s', 'ratchet' ), $plugin['name'] ),
					'target' => '_self',
					'nonce'  => wp_create_nonce( 'plugin-activation_' . $plugin['slug'] ),
				);

			}

			// Add "Delete" link.
			if ( 'inactive' === $plugin['status'] ) {

				$url = add_query_arg(
					array(
						'action'        => 'delete-selected',
						'verify-delete' => '1',
						'checked[]'     => urlencode( $plugin['file'] ),
						'_wpnonce'      => wp_create_nonce( 'bulk-plugins' ), // Formatted for plugins.php
					),
					self_admin_url( 'plugins.php' )
				);

				$actions['delete'] = array(
					'url'    => $url,
					'text'   => __( 'Delete', 'ratchet' ),
					// translators: 1: name of plugin
					'label'  => sprintf( __( 'Delete %s', 'ratchet' ), $plugin['name'] ),
					'target' => '_self',
					'nonce'  => wp_create_nonce( 'delete-plugin_' . $plugin['slug'] ),
				);

			}

			// Add "Update to version {version}" link or "Plugin is up-to-date" message.
			if ( $plugin['update'] ) {

				$url = add_query_arg(
					array(
						'action'   => 'upgrade-plugin',
						'plugin'   => urlencode( $plugin['file'] ),
						'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . $plugin['file'] ), // Formatted for update.php
					),
					self_admin_url( 'update.php' )
				);

				$actions['update'] = array(
					'url'    => $url,
					'text'   => sprintf(
						// translators: 1: new version of plugin
						__( 'Update to %s', 'ratchet' ),
						$plugin['new_version']
					),
					'label'  => sprintf(
						// translators: 1: name of plugin, 2: new version of plugin
						__( 'Update %1$s to version %2$s', 'ratchet' ),
						$plugin['name'],
						$plugin['new_version']
					),
					'target' => '_self',
					'nonce'  => null, // No nonce needed, using wp.updates.ajaxNonce.
				);

			} elseif ( 'not-installed' !== $plugin['status'] ) {

				$actions['update'] = array(
					'text' => __( 'Plugin is up-to-date', 'ratchet' ),
				);

			}

			// Build $output from $actions array data.
			$output = array();

			foreach ( $actions as $key => $action ) {

				if ( ! empty( $action['url'] ) ) {

					$class = '';

					if ( false !== strpos( $action['url'], get_site_url() ) ) {
						$class = $key . '-now';
					}

					if ( ! empty( $action['class'] ) ) {
						 $class .= ' ' . $action['class'];
					}

					$item = sprintf(
						'<span class="has-link %s"><a href="%s" class="edit %s" aria-label="%s" target="%s">%s</a></span>',
						$key,
						esc_url( $action['url'] ),
						esc_attr( $class ),
						esc_attr( $action['label'] ),
						esc_attr( $action['target'] ),
						esc_html( $action['text'] )
					);

					if ( ! empty( $action['nonce'] ) ) {

						$item = str_replace(
							'href',
							sprintf( 'data-ajax-nonce="%s" href', $action['nonce'] ),
							$item
						);

					}
				} else {

					$item = sprintf(
						'<span class="no-link %s">%s</span>',
						$key,
						esc_html( $action['text'] )
					);

				}

				$output[] = $item;

			}

			echo implode( ' | ', $output );

		}

		private function display_status( $plugin ) {

			switch ( $plugin['status'] ) {
				case 'active':
					esc_html_e( 'Active', 'ratchet' );
					break;

				case 'incompatible':
					esc_html_e( 'Incompatible', 'ratchet' );
					break;

				case 'inactive':
					esc_html_e( 'Installed', 'ratchet' );
					break;

				default:
					esc_html_e( 'Not Installed', 'ratchet' );
			}

		}

		public function add_plugins_view( $views ) {

			if ( ! $this->args['views_title'] ) {
				return $views;
			}

			$plugins = $this->plugins->get();

			if ( $plugins ) {

				$views['suggested'] = sprintf(
					"<a href='%s' title='%s'>%s <span class='count'>(%d)</span></a>",
					esc_url( $this->get_admin_url() ),
					esc_html( $this->args['extended_title'] ),
					esc_html( $this->args['views_title'] ),
					count( $plugins )
				);

			}

			return $views;

		}

		public function add_install_view( $tabs ) {

			if ( ! $this->args['tab_title'] ) {
				return $tabs;
			}

			if ( $this->plugins->get() ) {

				$tabs['suggested-by-theme'] = sprintf(
					"<a href='%s' title='%s'>%s</a>",
					esc_url( $this->get_admin_url() ),
					esc_html( $this->args['extended_title'] ),
					esc_html( $this->args['tab_title'] )
				);

			}

			return $tabs;

		}

		public function get_admin_url() {

			return add_query_arg(
				'page',
				$this->args['menu_slug'],
				admin_url( $this->args['parent_slug'] )
			);

		}

		public function get_plugin_source( $plugin ) {

			if ( false === strpos( $plugin['url'], 'wordpress.org' ) ) {
				return 'third-party';
			}

			return 'wordpress.org';

		}

		public function is_admin_screen() {

			$screen = get_current_screen();

			if ( $screen && $this->admin_screen_base === $screen->base ) {
				return true;
			}

			return false;

		}

		public function row_refresh() {

			if ( empty( $_POST['namespace'] ) || 'ratchet' !== $_POST['namespace'] ) {
				return;
			}

			check_ajax_referer( 'updates' );

			if ( ! $this->plugins->is_set() ) {
				$this->plugins->set();
			}

			$plugin = $this->plugins->get( $_POST['slug'] );

			if ( $plugin ) {

				if ( ! empty( $_POST['error'] ) ) {

					$plugin['notice'] = array(
						'class'   => 'notice-' . $_POST['error_level'],
						'message' => $_POST['error'],
					);

					$plugin['row-class'] = array( 'row-has-notice' );

				} else {

					$plugin['row-class'] = array( 'ajax-success' );

					if ( ! empty( $_POST['prev_action'] ) ) {

						$action_slug = str_replace( '-plugin', '', $_POST['prev_action'] );

						$plugin['row-class'][] = $action_slug . '-success';

					}
				}

				$this->display_table_row( $plugin );

			}

			wp_die();

		}

		public function request() {

			global $_REQUEST;

			// Do nothing if this isn't our admin screen.
			if ( ! $this->is_admin_screen() ) {
				return;
			}

			// Check for bulk actions.
			$do_bulk = false;

			if ( isset( $_REQUEST['action-top'] ) || isset( $_REQUEST['action-bottom'] ) ) {

				$do_bulk = true;

				if ( ! empty( $_REQUEST['action-top'] ) && -1 != $_REQUEST['action-top'] ) {

					$_REQUEST['action'] = $_REQUEST['action-top'];

				} elseif ( ! empty( $_REQUEST['action-bottom'] ) && -1 != $_REQUEST['action-bottom'] ) {

					$_REQUEST['action'] = $_REQUEST['action-bottom'];

				}
			}

			if ( empty( $_REQUEST['action'] ) ) {
				return;
			}

			$_SERVER['REQUEST_URI'] = remove_query_arg(
				array(
					'action',
					'success',
					'_error_nonce',
				),
				$_SERVER['REQUEST_URI']
			);

			if ( isset( $_REQUEST['success'] ) ) {

				$message = '';

				if ( 'activate' === $_REQUEST['action'] ) {

					$message = __( 'Plugin activated.', 'ratchet' );

				} elseif ( 'activate-selected' === $_REQUEST['action'] ) {

					$num = $_REQUEST['success'];

					$message = sprintf(
						// translators: 1: number of plugins activated without error
						_n(
							'%s plugin activated successfully.',
							'%s plugins activated successfully.',
							$num,
							'ratchet'
						),
						$num
					);

				} elseif ( 'deactivate' === $_REQUEST['action'] ) {

					$message = __( 'Plugin deactivated.', 'ratchet' );

				} elseif ( 'deactivate-selected' === $_REQUEST['action'] ) {

					$message = __( 'Plugins deactivated.', 'ratchet' );

				}

				if ( $message ) {

					add_settings_error(
						'plugin-manager',
						'plugin-manager-error',
						$message,
						'updated'
					);

				}

				return;

			}

			// Get all plugin data.
			$plugins_data = $this->plugins->get();

			$error = '';

			if ( $do_bulk && empty( $_REQUEST['checked'] ) ) {

				$error = __( 'No plugins were selected.', 'ratchet' );

			} elseif ( ! $do_bulk && empty( $_REQUEST['plugin'] ) ) {

				$error = __( 'No plugin slug was given.', 'ratchet' );

			} elseif ( ! current_user_can( 'update_plugins' ) ) {

				$error = __( 'Sorry, you are not allowed to update plugins for this site.', 'ratchet' );

			} elseif ( ! $do_bulk && empty( $plugins_data[ $_REQUEST['plugin'] ] ) ) {

				$error = sprintf(
					// translators: 1: slug of plugin being activated
					__( 'The plugin %s doesn\'t exist within the plugin manager\'s registered plugins.', 'ratchet' ),
					$_REQUEST['plugin']
				);

			}

			if ( ! $error ) {

				$success = 0; // Count of how many successful plugins activated on bulk.

				$redirect = add_query_arg(
					array(
						'action'  => $_REQUEST['action'],
						'success' => 1,
					),
					$this->get_admin_url()
				);

				if ( $do_bulk ) {

					$plugins = array();

					foreach ( $_REQUEST['checked'] as $slug ) {
						if ( ! empty( $plugins_data[ $slug ] ) ) {
							$plugins[ $slug ] = $plugins_data[ $slug ];
						}
					}

					if ( ! $plugins ) {
						$error = __( 'No valid plugins given for bulk action.', 'ratchet' );
					}
				} else {

					$plugin = $plugins_data[ $_REQUEST['plugin'] ];

				}

				// Perform action.
				if ( 'activate' === $_REQUEST['action'] ) {

					$result = activate_plugin( $plugin['file'], $redirect );

				} elseif ( 'activate-selected' === $_REQUEST['action'] ) {

					foreach ( $plugins as $plugin ) {

						$result = activate_plugin( $plugin['file'] );

						if ( ! is_wp_error( $result ) ) {
							$success++;
						}
					}

					if ( $success ) { // At least one plugin needed to be successful.
						$redirect = add_query_arg( 'success', $success, $redirect );
						wp_redirect( $redirect );
					} else {
						$error = __( 'None of the selected plugins could be activated. Make sure they are installed.', 'ratchet' );
					}
				} elseif ( 'deactivate' === $_REQUEST['action'] || 'deactivate-selected' === $_REQUEST['action'] ) {

					$deactivate = array();

					if ( $do_bulk ) {
						foreach ( $plugins as $plugin ) {
							$deactivate[] = trailingslashit( WP_PLUGIN_DIR ) . $plugin['file'];
						}
					} else {
						$deactivate[] = trailingslashit( WP_PLUGIN_DIR ) . $plugin['file'];
					}

					$result = deactivate_plugins( $deactivate );

					if ( ! is_wp_error( $result ) ) {
						wp_redirect( $redirect );
					}
				}

				if ( ! $error && is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				}
			}

			if ( $error ) {

				add_settings_error(
					'plugin-manager',
					'plugin-manager-error',
					$error,
					'error'
				);

			}

		}
	}