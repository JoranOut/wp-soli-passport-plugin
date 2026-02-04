<?php

namespace Soli\Passport;

use Soli\Passport\Database\Clients_Table;
use Soli\Passport\Database\User_Roles_Table;
use Soli\Passport\OIDC\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User Roles admin page
 *
 * Manages per-user and per-relatie role overrides for OIDC clients.
 */
class User_Roles {

	/**
	 * Notice message to display
	 *
	 * @var array|null
	 */
	private ?array $notice = null;

	/**
	 * Render the page
	 */
	public function render(): void {
		$this->process_actions();

		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';

		switch ( $action ) {
			case 'new':
				$this->render_form();
				break;
			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
				$this->render_form( $id );
				break;
			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Process form actions
	 */
	private function process_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle delete action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) ) {
			$this->handle_delete();
		}

		// Handle form submissions
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['soli_passport_user_role_nonce'] ) || ! wp_verify_nonce( $_POST['soli_passport_user_role_nonce'], 'soli_passport_user_role' ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'soli-passport' ),
			);
			return;
		}

		$action = isset( $_POST['form_action'] ) ? sanitize_text_field( $_POST['form_action'] ) : '';

		if ( $action === 'create' ) {
			$this->handle_create();
		} elseif ( $action === 'update' ) {
			$this->handle_update();
		}
	}

	/**
	 * Handle create action
	 */
	private function handle_create(): void {
		$override_type = isset( $_POST['override_type'] ) ? sanitize_text_field( $_POST['override_type'] ) : 'wp_user';
		$relatie_id    = isset( $_POST['relatie_id'] ) ? absint( $_POST['relatie_id'] ) : 0;
		$wp_user_id    = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( $_POST['client_id'] ) : '';
		$role          = isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : '';

		if ( empty( $client_id ) || empty( $role ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Client and Role are required.', 'soli-passport' ),
			);
			return;
		}

		// Validate role
		if ( ! Roles::is_valid_role( $role ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invalid role selected.', 'soli-passport' ),
			);
			return;
		}

		// Check if client exists
		$client = Clients_Table::get_by_client_id( $client_id );
		if ( ! $client ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Selected client does not exist.', 'soli-passport' ),
			);
			return;
		}

		if ( $override_type === 'relatie' && Dependency_Checker::is_enhanced_mode() ) {
			if ( ! $relatie_id ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Please select a relation.', 'soli-passport' ),
				);
				return;
			}

			// Check if relatie exists
			$relatie = Admin_Bridge::get_relatie_by_id( $relatie_id );
			if ( ! $relatie ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Selected relation does not exist.', 'soli-passport' ),
				);
				return;
			}

			// Check if assignment already exists
			$existing = User_Roles_Table::get_role_by_relatie( $relatie_id, $client_id );
			if ( $existing !== null ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'A role override already exists for this relation and client combination.', 'soli-passport' ),
				);
				return;
			}

			$result = User_Roles_Table::set_role_by_relatie( $relatie_id, $client_id, $role );
		} else {
			if ( ! $wp_user_id ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Please select a WordPress user.', 'soli-passport' ),
				);
				return;
			}

			// Check if user exists
			$user = get_user_by( 'ID', $wp_user_id );
			if ( ! $user ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Selected user does not exist.', 'soli-passport' ),
				);
				return;
			}

			// Check if assignment already exists
			$existing = User_Roles_Table::get_role( $wp_user_id, $client_id );
			if ( $existing !== null ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'A role override already exists for this user and client combination.', 'soli-passport' ),
				);
				return;
			}

			$result = User_Roles_Table::set_role( $wp_user_id, $client_id, $role );
		}

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role override created successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to create role override. Please try again.', 'soli-passport' ),
			);
		}
	}

	/**
	 * Handle update action
	 */
	private function handle_update(): void {
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$role = isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : '';

		if ( ! $id || empty( $role ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invalid request.', 'soli-passport' ),
			);
			return;
		}

		// Validate role
		if ( ! Roles::is_valid_role( $role ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invalid role selected.', 'soli-passport' ),
			);
			return;
		}

		// Get existing record to determine type
		$assignments = User_Roles_Table::get_all();
		$assignment  = null;
		foreach ( $assignments as $a ) {
			if ( (int) $a['id'] === $id ) {
				$assignment = $a;
				break;
			}
		}

		if ( ! $assignment ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Role override not found.', 'soli-passport' ),
			);
			return;
		}

		if ( ! empty( $assignment['wp_user_id'] ) ) {
			$result = User_Roles_Table::set_role( (int) $assignment['wp_user_id'], $assignment['client_id'], $role );
		} else {
			$result = User_Roles_Table::set_role_by_relatie( (int) $assignment['relatie_id'], $assignment['client_id'], $role );
		}

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role override updated successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to update role override. Please try again.', 'soli-passport' ),
			);
		}
	}

	/**
	 * Handle delete action
	 */
	private function handle_delete(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $id ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_passport_user_role_' . $id ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'soli-passport' ),
			);
			return;
		}

		$result = User_Roles_Table::delete( $id );

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role override deleted successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to delete role override. Please try again.', 'soli-passport' ),
			);
		}
	}

	/**
	 * Render the roles list
	 */
	private function render_list(): void {
		$filter_client = isset( $_GET['client'] ) ? sanitize_text_field( $_GET['client'] ) : null;
		$assignments   = User_Roles_Table::get_all( $filter_client );
		$clients       = Clients_Table::get_all( true );
		$is_enhanced   = Dependency_Checker::is_enhanced_mode();
		?>
		<div class="soli-admin-wrap">
			<h1><?php esc_html_e( 'User Role Overrides', 'soli-passport' ); ?></h1>

			<?php $this->render_notice(); ?>

			<p class="soli-page-description">
				<?php esc_html_e( 'Role overrides take precedence over role mappings. Use this to give specific users a different role than their group would normally assign.', 'soli-passport' ); ?>
			</p>

			<div class="soli-table-header">
				<div class="soli-record-count" data-total="<?php echo esc_attr( count( $assignments ) ); ?>">
					<?php
					printf(
						/* translators: %d: number of role overrides */
						esc_html( _n( '%d override', '%d overrides', count( $assignments ), 'soli-passport' ) ),
						count( $assignments )
					);
					?>
				</div>
				<div class="soli-table-actions">
					<select class="select select-bordered select-sm soli-filter-client" data-url="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles' ) ); ?>">
						<option value=""><?php esc_html_e( 'All clients', 'soli-passport' ); ?></option>
						<?php foreach ( $clients as $client ) : ?>
							<option value="<?php echo esc_attr( $client['client_id'] ); ?>" <?php selected( $filter_client, $client['client_id'] ); ?>>
								<?php echo esc_html( $client['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles&action=new' ) ); ?>" class="btn btn-primary btn-sm">
						<?php esc_html_e( 'Add Override', 'soli-passport' ); ?>
					</a>
				</div>
				<div class="soli-search-container">
					<input
						type="search"
						class="soli-search-input input input-bordered"
						placeholder="<?php esc_attr_e( 'Search...', 'soli-passport' ); ?>"
						data-table="soli-passport-user-roles-table"
					/>
				</div>
			</div>

			<div class="soli-table-container">
				<table class="soli-table table" id="soli-passport-user-roles-table">
					<thead>
						<tr>
							<th class="sortable" data-sort="type">
								<?php esc_html_e( 'Type', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="name">
								<?php esc_html_e( 'Name', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="client">
								<?php esc_html_e( 'Client', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="role">
								<?php esc_html_e( 'Role', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th><?php esc_html_e( 'Actions', 'soli-passport' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $assignments ) ) : ?>
							<tr>
								<td colspan="5" class="soli-empty-state">
									<?php esc_html_e( 'No role overrides found.', 'soli-passport' ); ?>
									<?php if ( ! empty( $clients ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles&action=new' ) ); ?>">
											<?php esc_html_e( 'Add your first override', 'soli-passport' ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport&action=new' ) ); ?>">
											<?php esc_html_e( 'Create a client first', 'soli-passport' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $assignments as $assignment ) : ?>
								<?php
								$is_wp_user = ! empty( $assignment['wp_user_id'] );
								$name       = '';
								$type_label = '';

								if ( $is_wp_user ) {
									$name       = $assignment['display_name'] ?: $assignment['user_login'];
									$type_label = __( 'WP User', 'soli-passport' );
								} else {
									$name       = trim( ( $assignment['voornaam'] ?? '' ) . ' ' . ( $assignment['tussenvoegsel'] ? $assignment['tussenvoegsel'] . ' ' : '' ) . ( $assignment['achternaam'] ?? '' ) );
									$type_label = __( 'Relation', 'soli-passport' );
								}
								?>
								<tr data-id="<?php echo esc_attr( $assignment['id'] ); ?>">
									<td data-column="type">
										<span class="soli-badge soli-badge-outline soli-badge-sm">
											<?php echo esc_html( $type_label ); ?>
										</span>
									</td>
									<td data-column="name"><?php echo esc_html( $name ); ?></td>
									<td data-column="client"><?php echo esc_html( $assignment['client_name'] ?: $assignment['client_id'] ); ?></td>
									<td data-column="role">
										<span class="soli-badge soli-badge-info"><?php echo esc_html( Roles::get_role_label( $assignment['role'] ) ); ?></span>
									</td>
									<td>
										<div class="soli-actions">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles&action=edit&id=' . $assignment['id'] ) ); ?>" class="btn btn-ghost btn-xs">
												<?php esc_html_e( 'Edit', 'soli-passport' ); ?>
											</a>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form
	 *
	 * @param int $id Assignment ID (0 for new)
	 */
	private function render_form( int $id = 0 ): void {
		$is_edit     = $id > 0;
		$is_enhanced = Dependency_Checker::is_enhanced_mode();
		$clients     = Clients_Table::get_all( true );

		if ( empty( $clients ) ) {
			$this->render_no_clients();
			return;
		}

		// Get existing assignment data for edit mode
		$assignment = null;
		$form_data  = array(
			'override_type' => 'wp_user',
			'relatie_id'    => 0,
			'wp_user_id'    => 0,
			'client_id'     => '',
			'role'          => '',
		);

		if ( $is_edit ) {
			$assignments = User_Roles_Table::get_all();
			foreach ( $assignments as $a ) {
				if ( (int) $a['id'] === $id ) {
					$assignment = $a;
					break;
				}
			}

			if ( $assignment ) {
				$form_data = array(
					'override_type' => ! empty( $assignment['wp_user_id'] ) ? 'wp_user' : 'relatie',
					'relatie_id'    => (int) ( $assignment['relatie_id'] ?? 0 ),
					'wp_user_id'    => (int) ( $assignment['wp_user_id'] ?? 0 ),
					'client_id'     => $assignment['client_id'],
					'role'          => $assignment['role'],
				);
			}
		}

		// If we have POST data from a failed validation, use that instead
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $this->notice && $this->notice['type'] === 'error' ) {
			$form_data = array(
				'override_type' => isset( $_POST['override_type'] ) ? sanitize_text_field( $_POST['override_type'] ) : 'wp_user',
				'relatie_id'    => isset( $_POST['relatie_id'] ) ? absint( $_POST['relatie_id'] ) : 0,
				'wp_user_id'    => isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0,
				'client_id'     => isset( $_POST['client_id'] ) ? sanitize_text_field( $_POST['client_id'] ) : '',
				'role'          => isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : '',
			);
		}

		// Get relaties for the dropdown (if enhanced mode)
		$relaties = $is_enhanced ? Admin_Bridge::get_all_relaties() : array();

		// Get users for the dropdown
		$users = get_users( array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 200,
		) );
		?>
		<div class="soli-admin-wrap">
			<div class="soli-detail-header">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles' ) ); ?>" class="soli-back-link">
					&larr; <?php esc_html_e( 'Back to role overrides', 'soli-passport' ); ?>
				</a>
				<h1>
					<?php
					if ( $is_edit ) {
						esc_html_e( 'Edit Role Override', 'soli-passport' );
					} else {
						esc_html_e( 'Add Role Override', 'soli-passport' );
					}
					?>
				</h1>
			</div>

			<?php $this->render_notice(); ?>

			<form method="post" class="soli-form">
				<?php wp_nonce_field( 'soli_passport_user_role', 'soli_passport_user_role_nonce' ); ?>
				<input type="hidden" name="form_action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<?php endif; ?>

				<div class="soli-card">
					<h3><?php esc_html_e( 'Role Override', 'soli-passport' ); ?></h3>

					<div class="soli-form-grid">
						<?php if ( ! $is_edit ) : ?>
							<?php if ( $is_enhanced ) : ?>
								<div class="soli-form-field soli-form-field-full">
									<label class="soli-label">
										<?php esc_html_e( 'Override Type', 'soli-passport' ); ?>
										<span class="soli-required">*</span>
									</label>
									<div class="soli-radio-group">
										<label class="soli-radio-label">
											<input
												type="radio"
												name="override_type"
												value="wp_user"
												class="radio"
												<?php checked( $form_data['override_type'], 'wp_user' ); ?>
												onchange="toggleOverrideType()"
											/>
											<?php esc_html_e( 'WordPress User', 'soli-passport' ); ?>
										</label>
										<label class="soli-radio-label">
											<input
												type="radio"
												name="override_type"
												value="relatie"
												class="radio"
												<?php checked( $form_data['override_type'], 'relatie' ); ?>
												onchange="toggleOverrideType()"
											/>
											<?php esc_html_e( 'Relation', 'soli-passport' ); ?>
										</label>
									</div>
									<p class="soli-field-hint"><?php esc_html_e( 'Relation-based overrides work regardless of which WordPress account the person uses.', 'soli-passport' ); ?></p>
								</div>
							<?php endif; ?>

							<div class="soli-form-field" id="wp-user-field" style="<?php echo ( $is_enhanced && $form_data['override_type'] === 'relatie' ) ? 'display:none;' : ''; ?>">
								<label for="wp_user_id" class="soli-label">
									<?php esc_html_e( 'WordPress User', 'soli-passport' ); ?>
									<span class="soli-required">*</span>
								</label>
								<select
									id="wp_user_id"
									name="wp_user_id"
									class="select select-bordered w-full"
								>
									<option value=""><?php esc_html_e( 'Select a user...', 'soli-passport' ); ?></option>
									<?php foreach ( $users as $user ) : ?>
										<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $form_data['wp_user_id'], $user->ID ); ?>>
											<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php if ( $is_enhanced ) : ?>
								<div class="soli-form-field" id="relatie-field" style="<?php echo $form_data['override_type'] === 'wp_user' ? 'display:none;' : ''; ?>">
									<label for="relatie_id" class="soli-label">
										<?php esc_html_e( 'Relation', 'soli-passport' ); ?>
										<span class="soli-required">*</span>
									</label>
									<select
										id="relatie_id"
										name="relatie_id"
										class="select select-bordered w-full"
									>
										<option value=""><?php esc_html_e( 'Select a relation...', 'soli-passport' ); ?></option>
										<?php foreach ( $relaties as $relatie ) : ?>
											<option value="<?php echo esc_attr( $relatie['id'] ); ?>" <?php selected( $form_data['relatie_id'], $relatie['id'] ); ?>>
												<?php echo esc_html( Admin_Bridge::get_relatie_display_name( $relatie ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>

							<div class="soli-form-field">
								<label for="client_id" class="soli-label">
									<?php esc_html_e( 'Client', 'soli-passport' ); ?>
									<span class="soli-required">*</span>
								</label>
								<select
									id="client_id"
									name="client_id"
									class="select select-bordered w-full"
									required
								>
									<option value=""><?php esc_html_e( 'Select a client...', 'soli-passport' ); ?></option>
									<?php foreach ( $clients as $client ) : ?>
										<option value="<?php echo esc_attr( $client['client_id'] ); ?>" <?php selected( $form_data['client_id'], $client['client_id'] ); ?>>
											<?php echo esc_html( $client['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php else : ?>
							<div class="soli-form-field">
								<label class="soli-label"><?php esc_html_e( 'Override For', 'soli-passport' ); ?></label>
								<p class="soli-field-value">
									<?php
									if ( $form_data['override_type'] === 'wp_user' && $assignment ) {
										echo esc_html( __( 'WP User:', 'soli-passport' ) . ' ' . ( $assignment['display_name'] ?: $assignment['user_login'] ) );
									} elseif ( $assignment ) {
										$name = trim( ( $assignment['voornaam'] ?? '' ) . ' ' . ( $assignment['tussenvoegsel'] ? $assignment['tussenvoegsel'] . ' ' : '' ) . ( $assignment['achternaam'] ?? '' ) );
										echo esc_html( __( 'Relation:', 'soli-passport' ) . ' ' . $name );
									}
									?>
								</p>
								<p class="soli-field-hint"><?php esc_html_e( 'Cannot be changed. Delete this override and create a new one if needed.', 'soli-passport' ); ?></p>
							</div>

							<div class="soli-form-field">
								<label class="soli-label"><?php esc_html_e( 'Client', 'soli-passport' ); ?></label>
								<p class="soli-field-value">
									<?php echo esc_html( $assignment['client_name'] ?? $assignment['client_id'] ); ?>
								</p>
								<p class="soli-field-hint"><?php esc_html_e( 'Cannot be changed. Delete this override and create a new one if needed.', 'soli-passport' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="soli-form-field <?php echo $is_edit ? '' : 'soli-form-field-full'; ?>">
							<label for="role" class="soli-label">
								<?php esc_html_e( 'Role', 'soli-passport' ); ?>
								<span class="soli-required">*</span>
							</label>
							<select
								id="role"
								name="role"
								class="select select-bordered w-full"
								required
							>
								<option value=""><?php esc_html_e( 'Select a role...', 'soli-passport' ); ?></option>
								<?php echo Roles::get_role_options_html( $form_data['role'] ); ?>
							</select>
							<p class="soli-field-hint"><?php esc_html_e( 'This role will be used for this specific user/relation and client combination, overriding any role mappings.', 'soli-passport' ); ?></p>
						</div>
					</div>
				</div>

				<div class="soli-form-actions">
					<button type="submit" class="btn btn-primary">
						<?php
						if ( $is_edit ) {
							esc_html_e( 'Update Override', 'soli-passport' );
						} else {
							esc_html_e( 'Create Override', 'soli-passport' );
						}
						?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles' ) ); ?>" class="btn btn-ghost">
						<?php esc_html_e( 'Cancel', 'soli-passport' ); ?>
					</a>
					<?php if ( $is_edit ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=soli-passport-user-roles&action=delete&id=' . $id ), 'delete_passport_user_role_' . $id ) ); ?>"
						   class="btn btn-error soli-delete-btn"
						   onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this role override?', 'soli-passport' ) ); ?>');">
							<?php esc_html_e( 'Delete Override', 'soli-passport' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( ! $is_edit && $is_enhanced ) : ?>
				<script>
					function toggleOverrideType() {
						const wpUserRadio = document.querySelector('input[name="override_type"][value="wp_user"]');
						const relatieField = document.getElementById('relatie-field');
						const wpUserField = document.getElementById('wp-user-field');

						if (wpUserRadio.checked) {
							wpUserField.style.display = '';
							relatieField.style.display = 'none';
						} else {
							wpUserField.style.display = 'none';
							relatieField.style.display = '';
						}
					}
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render message when no clients exist
	 */
	private function render_no_clients(): void {
		?>
		<div class="soli-admin-wrap">
			<div class="soli-detail-header">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-user-roles' ) ); ?>" class="soli-back-link">
					&larr; <?php esc_html_e( 'Back to role overrides', 'soli-passport' ); ?>
				</a>
				<h1><?php esc_html_e( 'Add Role Override', 'soli-passport' ); ?></h1>
			</div>

			<div class="soli-card">
				<p><?php esc_html_e( 'You need to create at least one OIDC client before you can add role overrides.', 'soli-passport' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport&action=new' ) ); ?>" class="btn btn-primary">
						<?php esc_html_e( 'Create OIDC Client', 'soli-passport' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render notice message
	 */
	private function render_notice(): void {
		if ( ! $this->notice ) {
			return;
		}

		$class = 'soli-notice soli-notice-' . esc_attr( $this->notice['type'] );
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo esc_html( $this->notice['message'] ); ?></p>
		</div>
		<?php
	}
}
