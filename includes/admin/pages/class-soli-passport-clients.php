<?php

namespace Soli\Passport;

use Soli\Passport\Database\Clients_Table;
use Soli\Passport\Database\User_Roles_Table;
use Soli\Passport\Database\Role_Mappings_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OIDC Clients admin page
 */
class Clients {

	/**
	 * Notice message to display
	 *
	 * @var array|null
	 */
	private ?array $notice = null;

	/**
	 * Generated secret to show after creating a client
	 *
	 * @var string|null
	 */
	private ?string $generated_secret = null;

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

		if ( ! isset( $_POST['soli_passport_client_nonce'] ) || ! wp_verify_nonce( $_POST['soli_passport_client_nonce'], 'soli_passport_client' ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'soli-passport' ),
			);
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';

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

		if ( empty( $data['client_id'] ) || empty( $data['name'] ) || empty( $data['redirect_uri'] ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Client ID, Name, and Redirect URI are required.', 'soli-passport' ),
			);
			return;
		}

		// Check if client_id already exists
		$existing = Clients_Table::get_by_client_id( $data['client_id'] );
		if ( $existing ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'A client with this Client ID already exists.', 'soli-passport' ),
			);
			return;
		}

		// Generate secret
		$secret         = Clients_Table::generate_secret();
		$data['secret'] = $secret;

		$result = Clients_Table::insert( $data );

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Client created successfully. Make sure to save the client secret - it will only be shown once!', 'soli-passport' ),
			);
			$this->generated_secret = $secret;
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to create client. Please try again.', 'soli-passport' ),
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
				'message' => __( 'Invalid client ID.', 'soli-passport' ),
			);
			return;
		}

		if ( empty( $data['name'] ) || empty( $data['redirect_uri'] ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Name and Redirect URI are required.', 'soli-passport' ),
			);
			return;
		}

		// Handle secret regeneration
		if ( isset( $_POST['regenerate_secret'] ) && $_POST['regenerate_secret'] === '1' ) {
			$secret                 = Clients_Table::generate_secret();
			$data['secret']         = $secret;
			$this->generated_secret = $secret;
		}

		$result = Clients_Table::update( $id, $data );

		if ( $result ) {
			$message = __( 'Client updated successfully.', 'soli-passport' );
			if ( $this->generated_secret ) {
				$message .= ' ' . __( 'New secret generated - save it now!', 'soli-passport' );
			}
			$this->notice = array(
				'type'    => 'success',
				'message' => $message,
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to update client. Please try again.', 'soli-passport' ),
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

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_passport_client_' . $id ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'soli-passport' ),
			);
			return;
		}

		$client = Clients_Table::get_by_id( $id );
		if ( ! $client ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Client not found.', 'soli-passport' ),
			);
			return;
		}

		// Delete associated user roles first
		User_Roles_Table::delete_by_client( $client['client_id'] );

		// Delete associated role mappings
		Role_Mappings_Table::delete_by_client( $client['client_id'] );

		// Delete the client
		$result = Clients_Table::delete( $id );

		if ( $result ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Client deleted successfully.', 'soli-passport' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to delete client. Please try again.', 'soli-passport' ),
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
			'client_id'    => isset( $_POST['client_id'] ) ? sanitize_text_field( $_POST['client_id'] ) : '',
			'name'         => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',
			'redirect_uri' => isset( $_POST['redirect_uri'] ) ? esc_url_raw( $_POST['redirect_uri'] ) : '',
			'actief'       => isset( $_POST['actief'] ) ? 1 : 0,
		);
	}

	/**
	 * Render the clients list
	 */
	private function render_list(): void {
		$clients       = Clients_Table::get_all();
		$show_inactive = isset( $_GET['show_inactive'] ) && $_GET['show_inactive'] === '1';

		if ( ! $show_inactive ) {
			$clients = array_filter( $clients, fn( $c ) => (bool) $c['actief'] );
		}
		?>
		<div class="soli-admin-wrap">
			<h1><?php esc_html_e( 'OIDC Clients', 'soli-passport' ); ?></h1>

			<?php $this->render_notice(); ?>

			<div class="soli-table-header">
				<div class="soli-record-count" data-total="<?php echo esc_attr( count( $clients ) ); ?>">
					<?php
					printf(
						/* translators: %d: number of clients */
						esc_html( _n( '%d client', '%d clients', count( $clients ), 'soli-passport' ) ),
						count( $clients )
					);
					?>
				</div>
				<div class="soli-table-actions">
					<label class="soli-checkbox-label">
						<input
							type="checkbox"
							class="soli-show-inactive"
							data-url="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport' ) ); ?>"
							<?php checked( $show_inactive ); ?>
						/>
						<?php esc_html_e( 'Show inactive', 'soli-passport' ); ?>
					</label>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport&action=new' ) ); ?>" class="btn btn-primary btn-sm">
						<?php esc_html_e( 'Add Client', 'soli-passport' ); ?>
					</a>
				</div>
				<div class="soli-search-container">
					<input
						type="search"
						class="soli-search-input input input-bordered"
						placeholder="<?php esc_attr_e( 'Search...', 'soli-passport' ); ?>"
						data-table="soli-passport-clients-table"
					/>
				</div>
			</div>

			<div class="soli-table-container">
				<table class="soli-table table" id="soli-passport-clients-table">
					<thead>
						<tr>
							<th class="sortable" data-sort="name">
								<?php esc_html_e( 'Name', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="client_id">
								<?php esc_html_e( 'Client ID', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="redirect_uri">
								<?php esc_html_e( 'Redirect URI', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th class="sortable" data-sort="status">
								<?php esc_html_e( 'Status', 'soli-passport' ); ?>
								<span class="sort-indicator"></span>
							</th>
							<th><?php esc_html_e( 'Actions', 'soli-passport' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $clients ) ) : ?>
							<tr>
								<td colspan="5" class="soli-empty-state">
									<?php esc_html_e( 'No clients found.', 'soli-passport' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport&action=new' ) ); ?>">
										<?php esc_html_e( 'Add your first client', 'soli-passport' ); ?>
									</a>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $clients as $client ) : ?>
								<tr data-id="<?php echo esc_attr( $client['id'] ); ?>">
									<td data-column="name"><?php echo esc_html( $client['name'] ); ?></td>
									<td data-column="client_id">
										<code><?php echo esc_html( $client['client_id'] ); ?></code>
									</td>
									<td data-column="redirect_uri">
										<code class="soli-truncate"><?php echo esc_html( $client['redirect_uri'] ); ?></code>
									</td>
									<td data-column="status">
										<?php if ( $client['actief'] ) : ?>
											<span class="soli-badge soli-badge-success"><?php esc_html_e( 'Active', 'soli-passport' ); ?></span>
										<?php else : ?>
											<span class="soli-badge soli-badge-neutral"><?php esc_html_e( 'Inactive', 'soli-passport' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<div class="soli-actions">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport&action=edit&id=' . $client['id'] ) ); ?>" class="btn btn-ghost btn-xs">
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
	 * @param int $id Client ID (0 for new)
	 */
	private function render_form( int $id = 0 ): void {
		$client  = null;
		$is_edit = $id > 0;

		if ( $is_edit ) {
			$client = Clients_Table::get_by_id( $id );
			if ( ! $client ) {
				$this->render_not_found();
				return;
			}
		}

		$form_data = $client ?? array(
			'client_id'    => '',
			'name'         => '',
			'redirect_uri' => '',
			'actief'       => 1,
		);

		// If we have POST data from a failed validation, use that instead
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $this->notice && $this->notice['type'] === 'error' ) {
			$form_data = array_merge( $form_data, $this->get_form_data() );
		}
		?>
		<div class="soli-admin-wrap">
			<div class="soli-detail-header">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport' ) ); ?>" class="soli-back-link">
					&larr; <?php esc_html_e( 'Back to clients', 'soli-passport' ); ?>
				</a>
				<h1>
					<?php
					if ( $is_edit ) {
						esc_html_e( 'Edit OIDC Client', 'soli-passport' );
					} else {
						esc_html_e( 'Add OIDC Client', 'soli-passport' );
					}
					?>
				</h1>
			</div>

			<?php $this->render_notice(); ?>

			<?php if ( $this->generated_secret ) : ?>
				<div class="soli-card soli-card-highlight">
					<h3><?php esc_html_e( 'Client Secret', 'soli-passport' ); ?></h3>
					<p class="soli-warning-text">
						<?php esc_html_e( 'Save this secret now. It will not be shown again!', 'soli-passport' ); ?>
					</p>
					<div class="soli-secret-display">
						<code id="client-secret"><?php echo esc_html( $this->generated_secret ); ?></code>
						<button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('client-secret').textContent);">
							<?php esc_html_e( 'Copy', 'soli-passport' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" class="soli-form">
				<?php wp_nonce_field( 'soli_passport_client', 'soli_passport_client_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<?php endif; ?>

				<div class="soli-card">
					<h3><?php esc_html_e( 'Client Details', 'soli-passport' ); ?></h3>

					<div class="soli-form-grid">
						<div class="soli-form-field">
							<label for="client_id" class="soli-label">
								<?php esc_html_e( 'Client ID', 'soli-passport' ); ?>
								<span class="soli-required">*</span>
							</label>
							<input
								type="text"
								id="client_id"
								name="client_id"
								value="<?php echo esc_attr( $form_data['client_id'] ); ?>"
								class="input input-bordered w-full"
								<?php echo $is_edit ? 'readonly' : 'required'; ?>
								placeholder="<?php esc_attr_e( 'e.g., app-soli-nl', 'soli-passport' ); ?>"
							/>
							<?php if ( $is_edit ) : ?>
								<p class="soli-field-hint"><?php esc_html_e( 'Client ID cannot be changed after creation.', 'soli-passport' ); ?></p>
							<?php else : ?>
								<p class="soli-field-hint"><?php esc_html_e( 'A unique identifier for this OAuth client. Use lowercase letters, numbers, and hyphens.', 'soli-passport' ); ?></p>
							<?php endif; ?>
						</div>

						<div class="soli-form-field">
							<label for="name" class="soli-label">
								<?php esc_html_e( 'Name', 'soli-passport' ); ?>
								<span class="soli-required">*</span>
							</label>
							<input
								type="text"
								id="name"
								name="name"
								value="<?php echo esc_attr( $form_data['name'] ); ?>"
								class="input input-bordered w-full"
								required
								placeholder="<?php esc_attr_e( 'e.g., Soli App', 'soli-passport' ); ?>"
							/>
							<p class="soli-field-hint"><?php esc_html_e( 'A display name for this client.', 'soli-passport' ); ?></p>
						</div>

						<div class="soli-form-field soli-form-field-full">
							<label for="redirect_uri" class="soli-label">
								<?php esc_html_e( 'Redirect URI', 'soli-passport' ); ?>
								<span class="soli-required">*</span>
							</label>
							<input
								type="url"
								id="redirect_uri"
								name="redirect_uri"
								value="<?php echo esc_attr( $form_data['redirect_uri'] ); ?>"
								class="input input-bordered w-full"
								required
								placeholder="<?php esc_attr_e( 'https://app.soli.nl/callback', 'soli-passport' ); ?>"
							/>
							<p class="soli-field-hint"><?php esc_html_e( 'The URL where users are redirected after authentication.', 'soli-passport' ); ?></p>
						</div>

						<div class="soli-form-field">
							<label class="soli-label soli-label-checkbox">
								<input
									type="checkbox"
									name="actief"
									value="1"
									class="checkbox"
									<?php checked( $form_data['actief'] ); ?>
								/>
								<?php esc_html_e( 'Active', 'soli-passport' ); ?>
							</label>
							<p class="soli-field-hint"><?php esc_html_e( 'Inactive clients cannot be used for authentication.', 'soli-passport' ); ?></p>
						</div>
					</div>
				</div>

				<?php if ( $is_edit ) : ?>
					<div class="soli-card">
						<h3><?php esc_html_e( 'Client Secret', 'soli-passport' ); ?></h3>
						<p><?php esc_html_e( 'The client secret is stored securely and cannot be retrieved. If you need a new secret, you can regenerate it below.', 'soli-passport' ); ?></p>
						<div class="soli-form-field">
							<label class="soli-label soli-label-checkbox">
								<input
									type="checkbox"
									name="regenerate_secret"
									value="1"
									class="checkbox"
								/>
								<?php esc_html_e( 'Regenerate client secret', 'soli-passport' ); ?>
							</label>
							<p class="soli-field-hint soli-warning-text"><?php esc_html_e( 'Warning: This will invalidate the current secret. All applications using this client will need to be updated.', 'soli-passport' ); ?></p>
						</div>
					</div>
				<?php endif; ?>

				<div class="soli-form-actions">
					<button type="submit" class="btn btn-primary">
						<?php
						if ( $is_edit ) {
							esc_html_e( 'Update Client', 'soli-passport' );
						} else {
							esc_html_e( 'Create Client', 'soli-passport' );
						}
						?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport' ) ); ?>" class="btn btn-ghost">
						<?php esc_html_e( 'Cancel', 'soli-passport' ); ?>
					</a>
					<?php if ( $is_edit ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=soli-passport&action=delete&id=' . $id ), 'delete_passport_client_' . $id ) ); ?>"
						   class="btn btn-error soli-delete-btn"
						   onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this client? This will also remove all role mappings and user overrides for this client.', 'soli-passport' ) ); ?>');">
							<?php esc_html_e( 'Delete Client', 'soli-passport' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>
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
				<p><?php esc_html_e( 'Client not found.', 'soli-passport' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=soli-passport' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to clients', 'soli-passport' ); ?>
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
