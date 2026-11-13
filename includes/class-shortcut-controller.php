<?php

declare(strict_types=1);

namespace JotLibrary;

use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Shortcut_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = 'jot-library/v1';
		$this->rest_base = 'entries';
	}

	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				(new self())->register_routes();
			}
		);
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => array($this, 'create_item'),
					'permission_callback' => array($this, 'create_item_permissions_check'),
					'args' => array(
						'url' => array(
							'description' => __('The public HTTP or HTTPS URL to save.', 'jot-library'),
							'type' => 'string',
							'required' => true,
							'minLength' => 1,
							'maxLength' => URL::MAX_LENGTH,
						),
						'title' => array(
							'description' => __('An optional curated title.', 'jot-library'),
							'type' => 'string',
							'default' => '',
							'maxLength' => 200,
						),
						'description' => array(
							'description' => __('An optional public summary.', 'jot-library'),
							'type' => 'string',
							'default' => '',
							'maxLength' => 500,
						),
						'notes' => array(
							'description' => __('Optional personal notes.', 'jot-library'),
							'type' => 'string',
							'default' => '',
						),
						'topics' => array(
							'description' => __('Optional topic names.', 'jot-library'),
							'type' => 'array',
							'default' => array(),
							'items' => array(
								'type' => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
						),
						'reading_status' => array(
							'description' => __('Whether the saved resource has been read.', 'jot-library'),
							'type' => 'string',
							'enum' => array('unread', 'read'),
							'default' => 'unread',
						),
						'fetch_metadata' => array(
							'description' => __('Whether to queue missing metadata and topic suggestions.', 'jot-library'),
							'type' => 'boolean',
							'default' => true,
						),
					),
				),
				'schema' => array($this, 'get_public_item_schema'),
			)
		);
	}

	public function create_item_permissions_check($request): bool|WP_Error {
		if (! is_user_logged_in()) {
			return new WP_Error(
				'jot_rest_not_authenticated',
				__('Authentication is required to save a library entry.', 'jot-library'),
				array('status' => 401)
			);
		}
		if (! current_user_can('create_jot_entries')) {
			return new WP_Error(
				'jot_rest_cannot_create',
				__('You are not allowed to save library entries.', 'jot-library'),
				array('status' => 403)
			);
		}

		return true;
	}

	public function create_item($request): WP_REST_Response|WP_Error {
		if (! $request instanceof WP_REST_Request) {
			return new WP_Error('jot_rest_invalid_request', __('The request could not be read.', 'jot-library'), array('status' => 400));
		}

		$topics = array_values(
			array_unique(
				array_filter(
					array_map('sanitize_text_field', (array) $request->get_param('topics'))
				)
			)
		);
		$result = (new Entry_Service())->create_or_find(
			array(
				'url' => (string) $request->get_param('url'),
				'title' => sanitize_text_field((string) $request->get_param('title')),
				'description' => sanitize_textarea_field((string) $request->get_param('description')),
				'notes' => wp_kses_post((string) $request->get_param('notes')),
				'tags' => $topics,
				'reading_status' => 'read' === $request->get_param('reading_status') ? 'read' : 'unread',
				'status' => 'draft',
			)
		);
		if (is_wp_error($result)) {
			return $this->prepare_error($result);
		}

		$queued = false;
		if ((bool) $request->get_param('fetch_metadata')) {
			$queued = Metadata_Enricher::enqueue((int) $result['id']);
		}

		$post = get_post((int) $result['id']);
		$status = $post instanceof WP_Post ? $post->post_status : 'draft';
		$edit_url = get_edit_post_link((int) $result['id'], 'raw');
		$code = (string) $result['code'];
		$response = new WP_REST_Response(
			array(
				'id' => (int) $result['id'],
				'created' => (bool) $result['created'],
				'status' => $status,
				'code' => $code,
				'short_url' => home_url('/j/' . rawurlencode($code)),
				'edit_url' => is_string($edit_url) ? $edit_url : '',
				'enrichment_queued' => $queued,
				'message' => $result['created']
					? sprintf(__('Saved as %s.', 'jot-library'), $code)
					: sprintf(__('Already saved as %s.', 'jot-library'), $code),
			),
			$result['created'] ? 201 : 200
		);
		$response->add_link('self', rest_url($this->namespace . '/' . $this->rest_base));

		return $response;
	}

	/** @return array<string,mixed> */
	public function get_item_schema(): array {
		if (is_array($this->schema)) {
			return $this->schema;
		}

		$this->schema = array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'jot-library-shortcut-entry',
			'type' => 'object',
			'properties' => array(
				'id' => array('type' => 'integer', 'readonly' => true),
				'created' => array('type' => 'boolean', 'readonly' => true),
				'status' => array('type' => 'string', 'readonly' => true),
				'code' => array('type' => 'string', 'readonly' => true),
				'short_url' => array('type' => 'string', 'format' => 'uri', 'readonly' => true),
				'edit_url' => array('type' => 'string', 'format' => 'uri', 'readonly' => true),
				'enrichment_queued' => array('type' => 'boolean', 'readonly' => true),
				'message' => array('type' => 'string', 'readonly' => true),
			),
		);

		return $this->schema;
	}

	private function prepare_error(WP_Error $error): WP_Error {
		$status = match ($error->get_error_code()) {
			'jot_invalid_url' => 400,
			'jot_busy' => 503,
			default => 500,
		};

		return new WP_Error($error->get_error_code(), $error->get_error_message(), array('status' => $status));
	}
}
