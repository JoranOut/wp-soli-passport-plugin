<?php

namespace Soli\Passport;

use Soli\Passport\Database\Clients_Table;
use Soli\Passport\Database\Role_Mappings_Table;
use Soli\Passport\OIDC\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role Mappings admin page
 *
 * Supports dual-mode:
 * - Standalone: WP role mappings only
 * - Enhanced: Relation type mappings + WP role mappings
 */
class Role_Mappings {

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

		if ( ! isset( $_POST['soli_passport_role_mapping_nonce'] ) || ! wp_verify_nonce( $_POST['soli_passport_role_mapping_nonce'], 'soli_passport_role_mapping' ) ) {
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
		$data = $this->get_form_data();

		if ( empty( $data['client_id'] ) || empty( $data['role'] ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Client and Role are required.', 'soli-passport' ),
			);
			return;
		}

		$mapping_type = $data['mapping_type'];

		if ( $mapping_type === Role_Mappings_Table::TYPE_WP_ROLE ) {
			if ( empty( $data['wp_role'] ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'WordPress Role is required.', 'soli-passport' ),
				);
				return;
			}

			$result = Role_Mappings_Table::set_wp_role_mapping(
				$data['client_id'],
				$data['wp_role'],
				$data['role'],
				$data['priority']
			);
		} else {
			if ( empty( $data['relatie_type_id'] ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Relation Type is required.', 'soli-passport' ),
				);
				return;
			}

			$result = Role_Mappings_Table::set_relatie_type_mapping(
				$data['client_id'],
				(int) $data['relatie_type_id'],
				$data['role'],
				$data['priority']
			);
		}

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role mapping created successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to create role mapping. It may already exist.', 'soli-passport' ),
			);
		}
	}

	/**
	 * Handle update action
	 */
	private function handle_update(): void {
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = $this->get_form_data();

		if ( ! $id ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invalid mapping ID.', 'soli-passport' ),
			);
			return;
		}

		if ( empty( $data['role'] ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Role is required.', 'soli-passport' ),
			);
			return;
		}

		// Get existing mapping
		$existing = Role_Mappings_Table::get_by_id( $id );
		if ( ! $existing ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Mapping not found.', 'soli-passport' ),
			);
			return;
		}

		// Update based on type
		if ( $existing['mapping_type'] === Role_Mappings_Table::TYPE_WP_ROLE ) {
			$result = Role_Mappings_Table::set_wp_role_mapping(
				$existing['client_id'],
				$existing['wp_role'],
				$data['role'],
				$data['priority']
			);
		} else {
			$result = Role_Mappings_Table::set_relatie_type_mapping(
				$existing['client_id'],
				(int) $existing['relatie_type_id'],
				$data['role'],
				$data['priority']
			);
		}

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role mapping updated successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to update role mapping.', 'soli-passport' ),
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

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_passport_role_mapping_' . $id ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'soli-passport' ),
			);
			return;
		}

		$result = Role_Mappings_Table::delete( $id );

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Role mapping deleted successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to delete role mapping.', 'soli-passport' ),
			);
		}
	}

	/**
	 * Get form data from POST
	 *
	 * @return array
	 */
	private function get_form_data(): array {
		return array(
			'client_id'       => isset( $_POST['client_id'] ) ? sanitize_text_field( $_POST['client_id'] ) : '',
			'mapping_type'    => isset( $_POST['mapping_type'] ) ? sanitize_text_field( $_POST['mapping_type'] ) : Role_Mappings_Table::TYPE_WP_ROLE,
			'wp_role'         => isset( $_POST['wp_role'] ) ? sanitize_text_field( $_POST['wp_role'] ) : '',
			'relatie_type_id' => isset( $_POST['relatie_type_id'] ) ? absint( $_POST['relatie_type_id'] ) : 0,
			'role'            => isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : '',
			'priority'        => isset( $_POST['priority'] ) ? absint( $_POST['priority'] ) : 0,
		);
	}

	/**
	 * Render the mappings list
	 */
	private function render_list(): void {
		$filter_client_id = isset( $_GET['client'] ) ? sanitize_text_field( $_GET['client'] ) : null;
		$mappings         = Role_Mappings_Table::get_all( $filter_client_id );
		$clients          = Clients_Table::get_all( true );
		$is_enhanced      = Dependency_Checker::is_enhanced_mode();
		?>
		<div class="soli-admin-wrap">
			<h1><?php esc_html_e( 'Role Mappings', 'soli-passport' ); ?></h1>

			<p class="soli-page-description">
				<?php if ( $is_enhanced ) : ?>
					<?php esc_html_e( 'Configure role assignments based on relation types or WordPress roles. Relation type mappings take precedence over WordPress role mappings.', 'soli-passport' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Configure role assignments based on WordPress roles. Install the Soli Admin Plugin for relation type mappings.', 'soli-passport' ); ?>
				<?php endif; ?>
			</p>

			<?php $this->render_notice(); ?>

			<div class="soli-table-header">
				<div class="soli-record-count" data-total="<?php echo esc_attr( count( $mappings ) ); ?>">
					<?php
					printf(
						/* translators: %d: number of mappings */
						esc_html( _n( '%d mapping', '%d mappings', count( $mappings ), 'soli-passport' ) ),
						count( $mappings )
					);
					?>
				</div>
				<div class="soli-table-actions">
					<select class="select select-bordered select-sm soli-filter-select" onchange="window.location.href = this.value;">
						<option value="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings' ) ); ?>">
							<?php esc_html_e( 'All clients', 'soli-passport' ); ?>
						</option>
						<?php foreach ( $clients as $client ) : ?>
							<option
								value="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings&client=' . $client['client_id'] ) ); ?>"
								<?php selected( $filter_client_id, $client['client_id'] ); ?>
							>
								<?php echo esc_html( $client['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings&action=new' ) ); ?>" class="btn btn-primary btn-sm">
						<?php esc_html_e( 'Add Mapping', 'soli-passport' ); ?>
					</a>
				</div>
				<div class="soli-search-container">
					<input
						type="search"
						class="soli-search-input input input-bordered"
						placeholder="<?php esc_attr_e( 'Search...', 'soli-passport' ); ?>"
						data-table="soli-passport-role-mappings-table"
					/>
				</div>
			</div>

			<div class="soli-table-container">
				<table class="soli-table table" id="soli-passport-role-mappings-table">
					<thead>
						<tr>
							<th class="sortable" data-sort="client">
								<?php esc_html_e( 'Client', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="source">
								<?php esc_html_e( 'Source', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="priority">
								<?php esc_html_e( 'Priority', 'soli-passport' ); ?>
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
						<?php if ( empty( $mappings ) ) : ?>
							<tr>
								<td colspan="5" class="soli-empty-state">
									<?php esc_html_e( 'No role mappings found.', 'soli-passport' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings&action=new' ) ); ?>">
										<?php esc_html_e( 'Add your first mapping', 'soli-passport' ); ?>
									</a>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $mappings as $mapping ) : ?>
								<?php
								$is_wp_role = $mapping['mapping_type'] === Role_Mappings_Table::TYPE_WP_ROLE;
								$type_label = $is_wp_role ? __( 'WP Role', 'soli-passport' ) : __( 'Relation', 'soli-passport' );
								$source     = $is_wp_role
									? ucfirst( $mapping['wp_role'] ?? '' )
									: ucfirst( $mapping['type_naam'] ?? '' );
								?>
								<tr data-id="<?php echo esc_attr( $mapping['id'] ); ?>">
									<td data-column="client"><?php echo esc_html( $mapping['client_name'] ?? $mapping['client_id'] ); ?></td>
									<td data-column="source">
										<span class="soli-badge soli-badge-outline soli-badge-sm"><?php echo esc_html( $type_label ); ?></span>
										<?php echo esc_html( $source ); ?>
									</td>
									<td data-column="priority"><?php echo esc_html( $mapping['priority'] ); ?></td>
									<td data-column="role">
										<span class="soli-badge <?php echo $mapping['role'] === 'no-access' ? 'soli-badge-error' : 'soli-badge-info'; ?>">
											<?php echo esc_html( Roles::get_role_label( $mapping['role'] ) ); ?>
										</span>
									</td>
									<td>
										<div class="soli-actions">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings&action=edit&id=' . $mapping['id'] ) ); ?>" class="btn btn-ghost btn-xs">
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

			<div class="soli-info-card">
				<h3><?php esc_html_e( 'How Role Mappings Work', 'soli-passport' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'When a user authenticates via OIDC, the system checks for user-specific overrides first.', 'soli-passport' ); ?></li>
					<?php if ( $is_enhanced ) : ?>
						<li><?php esc_html_e( 'If no override exists, it looks up the user\'s relation and checks for relation type mappings.', 'soli-passport' ); ?></li>
					<?php endif; ?>
					<li><?php esc_html_e( 'If no match is found, WordPress role mappings are used as fallback.', 'soli-passport' ); ?></li>
					<li><?php esc_html_e( 'Higher priority mappings take precedence when multiple match.', 'soli-passport' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form
	 *
	 * @param int $id Mapping ID (0 for new)
	 */
	private function render_form( int $id = 0 ): void {
		$mapping     = null;
		$is_edit     = $id > 0;
		$is_enhanced = Dependency_Checker::is_enhanced_mode();

		if ( $is_edit ) {
			$mapping = Role_Mappings_Table::get_by_id( $id );
			if ( ! $mapping ) {
				$this->render_not_found();
				return;
			}
		}

		$form_data = $mapping ?? array(
			'client_id'       => '',
			'mapping_type'    => Role_Mappings_Table::TYPE_WP_ROLE,
			'wp_role'         => '',
			'relatie_type_id' => 0,
			'role'            => '',
			'priority'        => 0,
		);

		// If we have POST data from a failed validation, use that instead
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $this->notice && $this->notice['type'] === 'error' ) {
			$form_data = array_merge( $form_data, $this->get_form_data() );
		}

		$clients       = Clients_Table::get_all( true );
		$relatie_types = Admin_Bridge::get_all_relatie_types();
		$wp_roles      = Roles::get_wp_roles();
		?>
		<div class="soli-admin-wrap">
			<div class="soli-detail-header">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings' ) ); ?>" class="soli-back-link">
					&larr; <?php esc_html_e( 'Back to mappings', 'soli-passport' ); ?>
				</a>
				<h1>
					<?php
					if ( $is_edit ) {
						esc_html_e( 'Edit Role Mapping', 'soli-passport' );
					} else {
						esc_html_e( 'Add Role Mapping', 'soli-passport' );
					}
					?>
				</h1>
			</div>

			<?php $this->render_notice(); ?>

			<form method="post" class="soli-form">
				<?php wp_nonce_field( 'soli_passport_role_mapping', 'soli_passport_role_mapping_nonce' ); ?>
				<input type="hidden" name="form_action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<?php endif; ?>

				<div class="soli-card">
					<h3><?php esc_html_e( 'Mapping Details', 'soli-passport' ); ?></h3>

					<div class="soli-form-grid">
						<?php if ( ! $is_edit ) : ?>
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
									<option value=""><?php esc_html_e( '-- Select Client --', 'soli-passport' ); ?></option>
									<?php foreach ( $clients as $client ) : ?>
										<option value="<?php echo esc_attr( $client['client_id'] ); ?>" <?php selected( $form_data['client_id'], $client['client_id'] ); ?>>
											<?php echo esc_html( $client['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="soli-form-field">
								<label class="soli-label">
									<?php esc_html_e( 'Mapping Type', 'soli-passport' ); ?>
									<span class="soli-required">*</span>
								</label>
								<div class="soli-radio-group">
									<label class="soli-radio-label">
										<input
											type="radio"
											name="mapping_type"
											value="<?php echo esc_attr( Role_Mappings_Table::TYPE_WP_ROLE ); ?>"
											class="radio"
											<?php checked( $form_data['mapping_type'], Role_Mappings_Table::TYPE_WP_ROLE ); ?>
											onchange="toggleMappingType()"
										/>
										<?php esc_html_e( 'WordPress Role', 'soli-passport' ); ?>
									</label>
									<?php if ( $is_enhanced ) : ?>
										<label class="soli-radio-label">
											<input
												type="radio"
												name="mapping_type"
												value="<?php echo esc_attr( Role_Mappings_Table::TYPE_RELATIE_TYPE ); ?>"
												class="radio"
												<?php checked( $form_data['mapping_type'], Role_Mappings_Table::TYPE_RELATIE_TYPE ); ?>
												onchange="toggleMappingType()"
											/>
											<?php esc_html_e( 'Relation Type', 'soli-passport' ); ?>
										</label>
									<?php endif; ?>
								</div>
							</div>

							<div class="soli-form-field" id="wp-role-field" style="<?php echo $form_data['mapping_type'] !== Role_Mappings_Table::TYPE_WP_ROLE ? 'display:none;' : ''; ?>">
								<label for="wp_role" class="soli-label">
									<?php esc_html_e( 'WordPress Role', 'soli-passport' ); ?>
									<span class="soli-required">*</span>
								</label>
								<select
									id="wp_role"
									name="wp_role"
									class="select select-bordered w-full"
								>
									<option value=""><?php esc_html_e( '-- Select WP Role --', 'soli-passport' ); ?></option>
									<?php foreach ( $wp_roles as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $form_data['wp_role'], $slug ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php if ( $is_enhanced ) : ?>
								<div class="soli-form-field" id="relatie-type-field" style="<?php echo $form_data['mapping_type'] !== Role_Mappings_Table::TYPE_RELATIE_TYPE ? 'display:none;' : ''; ?>">
									<label for="relatie_type_id" class="soli-label">
										<?php esc_html_e( 'Relation Type', 'soli-passport' ); ?>
										<span class="soli-required">*</span>
									</label>
									<select
										id="relatie_type_id"
										name="relatie_type_id"
										class="select select-bordered w-full"
									>
										<option value=""><?php esc_html_e( '-- Select Relation Type --', 'soli-passport' ); ?></option>
										<?php foreach ( $relatie_types as $type ) : ?>
											<option value="<?php echo esc_attr( $type['id'] ); ?>" <?php selected( $form_data['relatie_type_id'], $type['id'] ); ?>>
												<?php echo esc_html( ucfirst( $type['naam'] ) ); ?> (<?php echo esc_html( __( 'priority', 'soli-passport' ) . ': ' . $type['volgorde'] ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<div class="soli-form-field">
								<label class="soli-label"><?php esc_html_e( 'Client', 'soli-passport' ); ?></label>
								<p class="soli-field-value"><?php echo esc_html( $mapping['client_name'] ?? $mapping['client_id'] ); ?></p>
								<p class="soli-field-hint"><?php esc_html_e( 'Cannot be changed. Delete and create a new mapping if needed.', 'soli-passport' ); ?></p>
							</div>

							<div class="soli-form-field">
								<label class="soli-label"><?php esc_html_e( 'Source', 'soli-passport' ); ?></label>
								<p class="soli-field-value">
									<?php
									if ( $mapping['mapping_type'] === Role_Mappings_Table::TYPE_WP_ROLE ) {
										echo esc_html__( 'WP Role: ', 'soli-passport' ) . esc_html( ucfirst( $mapping['wp_role'] ) );
									} else {
										echo esc_html__( 'Relation Type: ', 'soli-passport' ) . esc_html( ucfirst( $mapping['type_naam'] ?? '' ) );
									}
									?>
								</p>
								<p class="soli-field-hint"><?php esc_html_e( 'Cannot be changed. Delete and create a new mapping if needed.', 'soli-passport' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="soli-form-field">
							<label for="role" class="soli-label">
								<?php esc_html_e( 'Assigned Role', 'soli-passport' ); ?>
								<span class="soli-required">*</span>
							</label>
							<select
								id="role"
								name="role"
								class="select select-bordered w-full"
								required
							>
								<option value=""><?php esc_html_e( '-- Select Role --', 'soli-passport' ); ?></option>
								<?php echo Roles::get_role_options_html( $form_data['role'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</select>
							<p class="soli-field-hint"><?php esc_html_e( 'The role to include in the OIDC token for matching users.', 'soli-passport' ); ?></p>
						</div>

						<div class="soli-form-field">
							<label for="priority" class="soli-label">
								<?php esc_html_e( 'Priority', 'soli-passport' ); ?>
							</label>
							<input
								type="number"
								id="priority"
								name="priority"
								value="<?php echo esc_attr( $form_data['priority'] ); ?>"
								class="input input-bordered w-full"
								min="0"
								max="100"
							/>
							<p class="soli-field-hint"><?php esc_html_e( 'Higher priority mappings take precedence. Default: 0.', 'soli-passport' ); ?></p>
						</div>
					</div>
				</div>

				<div class="soli-form-actions">
					<button type="submit" class="btn btn-primary">
						<?php
						if ( $is_edit ) {
							esc_html_e( 'Update Mapping', 'soli-passport' );
						} else {
							esc_html_e( 'Create Mapping', 'soli-passport' );
						}
						?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings' ) ); ?>" class="btn btn-ghost">
						<?php esc_html_e( 'Cancel', 'soli-passport' ); ?>
					</a>
					<?php if ( $is_edit ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=soli-passport-role-mappings&action=delete&id=' . $id ), 'delete_passport_role_mapping_' . $id ) ); ?>"
						   class="btn btn-error soli-delete-btn"
						   onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this mapping?', 'soli-passport' ) ); ?>');">
							<?php esc_html_e( 'Delete Mapping', 'soli-passport' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( ! $is_edit ) : ?>
				<script>
					function toggleMappingType() {
						const wpRoleRadio = document.querySelector('input[name="mapping_type"][value="wp_role"]');
						const wpRoleField = document.getElementById('wp-role-field');
						const relatieTypeField = document.getElementById('relatie-type-field');

						if (wpRoleRadio && wpRoleRadio.checked) {
							if (wpRoleField) wpRoleField.style.display = '';
							if (relatieTypeField) relatieTypeField.style.display = 'none';
						} else {
							if (wpRoleField) wpRoleField.style.display = 'none';
							if (relatieTypeField) relatieTypeField.style.display = '';
						}
					}
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render not found message
	 */
	private function render_not_found(): void {
		?>
		<div class="soli-admin-wrap">
			<div class="soli-notice soli-notice-error">
				<p><?php esc_html_e( 'Mapping not found.', 'soli-passport' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport-role-mappings' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to mappings', 'soli-passport' ); ?>
				</a>
			</p>
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
