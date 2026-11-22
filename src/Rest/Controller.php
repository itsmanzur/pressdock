<?php
/**
 * REST API: pressdock/v1.
 *
 * @package Pressdock
 */

namespace Pressdock\Rest;

use Pressdock\Admin\Devices;
use Pressdock\Registry;
use Pressdock\Settings;
use Pressdock\Store\Changes;
use Pressdock\Store\Records;
use Pressdock\Sync\Baseline;
use Pressdock\Sync\Presenter;
use Pressdock\Sync\PushProcessor;
use Pressdock\Sync\Undo;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves every route. Authentication is WordPress core (Application Passwords).
 */
final class Controller {

	const NAMESPACE_ = 'pressdock/v1';
	const MAX_PULL   = 500;
	const DEF_PULL   = 200;

	/**
	 * Register routes. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$auth = array( __CLASS__, 'permission' );

		register_rest_route(
			self::NAMESPACE_,
			'/handshake',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handshake' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/entities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'entities' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/pull',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'pull' ),
				'permission_callback' => $auth,
				'args'                => array(
					'entity' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'since'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => self::DEF_PULL,
						'minimum' => 1,
						'maximum' => self::MAX_PULL,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/push',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'push' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'undo' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'media_get' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'media_upload' ),
					'permission_callback' => array( __CLASS__, 'permission_upload' ),
				),
			)
		);
	}

	/**
	 * Whether the connection is secure enough: HTTPS, WP_DEBUG, or an allowed local development host.
	 *
	 * @return bool
	 */
	public static function transport_ok() {
		if ( is_ssl() || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return true;
		}
		return (bool) Settings::get( 'allow_local_http' ) && self::is_local_host();
	}

	/**
	 * Whether the site runs on a local development host.
	 *
	 * @return bool
	 */
	public static function is_local_host() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Base permission: secure transport and a signed-in user.
	 *
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( ! self::transport_ok() ) {
			return new WP_Error( 'pdock_insecure_transport', __( 'Pressdock requires HTTPS.', 'pressdock' ), array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'pdock_not_authenticated', __( 'Authentication is required. Use an Application Password.', 'pressdock' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Administrator only.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_admin() {
		$base = self::permission();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'pdock_forbidden', __( 'Administrators only.', 'pressdock' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Media upload permission.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_upload() {
		$base = self::permission();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'pdock_forbidden', __( 'You are not allowed to upload files.', 'pressdock' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /handshake.
	 *
	 * @return WP_REST_Response
	 */
	public static function handshake() {
		global $wp_version;
		$user     = wp_get_current_user();
		$entities = array();
		foreach ( Registry::all() as $adapter ) {
			$actions = Registry::allowed_actions( $adapter );
			if ( in_array( true, $actions, true ) ) {
				$entities[] = array(
					'key'     => $adapter->key(),
					'label'   => $adapter->label(),
					'group'   => $adapter->group(),
					'actions' => $actions,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'site'        => array(
					'name'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'url'            => home_url(),
					'rest_url'       => rest_url( self::NAMESPACE_ ),
					'wp_version'     => $wp_version,
					'plugin_version' => PDOCK_VERSION,
					'api_version'    => PDOCK_API_VERSION,
					'timezone'       => wp_timezone_string(),
				),
				'user'        => array(
					'id'           => $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
				),
				'entities'    => $entities,
				'cursor'      => Changes::max_id(),
				'limits'      => array(
					'max_ops'      => PushProcessor::MAX_OPS,
					'pull_default' => self::DEF_PULL,
					'pull_max'     => self::MAX_PULL,
				),
				'https'       => is_ssl(),
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /entities.
	 *
	 * @return WP_REST_Response
	 */
	public static function entities() {
		$out = array();
		foreach ( Registry::all() as $adapter ) {
			$actions = Registry::allowed_actions( $adapter );
			if ( ! in_array( true, $actions, true ) ) {
				continue;
			}
			$out[] = array(
				'key'     => $adapter->key(),
				'label'   => $adapter->label(),
				'group'   => $adapter->group(),
				'actions' => $actions,
				'schema'  => Presenter::schema( $adapter ),
			);
		}
		return new WP_REST_Response( array( 'entities' => $out ) );
	}

	/**
	 * GET /pull.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pull( WP_REST_Request $request ) {
		$since  = (int) $request->get_param( 'since' );
		$limit  = (int) $request->get_param( 'limit' );
		$limit  = $limit < 1 ? self::DEF_PULL : min( $limit, self::MAX_PULL );
		$filter = (string) $request->get_param( 'entity' );

		$adapters = array();
		if ( '' !== $filter ) {
			$adapter = Registry::get( $filter );
			if ( ! $adapter ) {
				return new WP_Error( 'pdock_unknown_entity', __( 'Unknown or disabled entity.', 'pressdock' ), array( 'status' => 404 ) );
			}
			if ( ! Registry::user_can( $adapter, 'read' ) ) {
				return new WP_Error( 'pdock_forbidden', __( 'You are not allowed to read this entity.', 'pressdock' ), array( 'status' => 403 ) );
			}
			$adapters[ $filter ] = $adapter;
		} else {
			foreach ( Registry::all() as $key => $adapter ) {
				if ( Registry::user_can( $adapter, 'read' ) ) {
					$adapters[ $key ] = $adapter;
				}
			}
		}

		$pending = false;
		foreach ( $adapters as $adapter ) {
			// Optional: adapters whose plugin fires no hooks detect outside changes here.
			if ( method_exists( $adapter, 'refresh' ) ) {
				$adapter->refresh();
			}
			if ( ! Baseline::advance( $adapter ) ) {
				$pending = true;
			}
		}

		$rows     = Changes::since( $since, array_keys( $adapters ), $limit + 1 );
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );

		$next_cursor = $since;
		$latest      = array();
		foreach ( $rows as $row ) {
			$next_cursor                                    = (int) $row['id'];
			$latest[ $row['entity'] . ':' . $row['object_id'] ] = $row;
		}

		$by_entity = array();
		foreach ( $latest as $row ) {
			if ( 'upsert' === $row['action'] ) {
				$by_entity[ $row['entity'] ][] = (int) $row['object_id'];
			}
		}
		$loaded = array();
		foreach ( $by_entity as $key => $ids ) {
			$loaded[ $key ] = $adapters[ $key ]->list( $ids );
		}

		$items = array();
		foreach ( $latest as $row ) {
			$adapter = $adapters[ $row['entity'] ];
			$id      = (int) $row['object_id'];

			if ( 'upsert' === $row['action'] ) {
				if ( method_exists( $adapter, 'can_object' ) && ! $adapter->can_object( 'read', $id ) ) {
					continue;
				}
				$data = isset( $loaded[ $row['entity'] ][ $id ] ) ? $loaded[ $row['entity'] ][ $id ] : false;
				$item = Presenter::record( $adapter, $id, false === $data ? null : $data );
				if ( false === $data ) {
					$item['deleted'] = true;
					$item['data']    = null;
				}
			} else {
				$record = Records::ensure( $row['entity'], $id );
				$item   = array(
					'entity'    => $row['entity'],
					'remote_id' => $id,
					'uuid'      => $record ? $record['uuid'] : null,
					'revision'  => $record ? (int) $record['revision'] : (int) $row['revision'],
					'deleted'   => true,
					'data'      => null,
				);
			}
			$item['action']     = $row['action'];
			$item['changed_at'] = str_replace( ' ', 'T', $row['changed_at'] ) . 'Z';
			$item['source']     = $row['source'];
			$item['cursor']     = (int) $row['id'];
			$items[]            = $item;
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['cursor'] - $b['cursor'];
			}
		);

		Devices::log( 'pull' );

		return new WP_REST_Response(
			array(
				'items'            => $items,
				'next_cursor'      => $next_cursor,
				'has_more'         => $has_more || $pending,
				'baseline_pending' => $pending,
				'server_time'      => gmdate( 'c' ),
			)
		);
	}

	/**
	 * POST /push.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function push( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$batch_id = isset( $body['batch_id'] ) && is_string( $body['batch_id'] ) ? $body['batch_id'] : '';
		$ops      = isset( $body['ops'] ) && is_array( $body['ops'] ) ? array_values( $body['ops'] ) : null;

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,36}$/', $batch_id ) ) {
			return new WP_Error( 'pdock_bad_batch_id', __( 'batch_id must be 8-36 characters of letters, digits and dashes.', 'pressdock' ), array( 'status' => 400 ) );
		}
		if ( null === $ops || array() === $ops ) {
			return new WP_Error( 'pdock_no_ops', __( 'ops must be a non-empty array.', 'pressdock' ), array( 'status' => 400 ) );
		}
		if ( count( $ops ) > PushProcessor::MAX_OPS ) {
			return new WP_Error( 'pdock_too_many_ops', __( 'A batch may hold at most 100 operations.', 'pressdock' ), array( 'status' => 413 ) );
		}

		$results = PushProcessor::run( $batch_id, $ops );
		Devices::log( 'push' );

		return new WP_REST_Response(
			array(
				'batch_id'    => $batch_id,
				'results'     => $results,
				'cursor'      => Changes::max_id(),
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * POST /undo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function undo( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$batch_id = is_array( $body ) && isset( $body['batch_id'] ) && is_string( $body['batch_id'] ) ? $body['batch_id'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,36}$/', $batch_id ) ) {
			return new WP_Error( 'pdock_bad_batch_id', __( 'batch_id is required.', 'pressdock' ), array( 'status' => 400 ) );
		}
		$result = Undo::run( $batch_id );
		if ( 0 === $result['restored'] && array() === $result['errors'] ) {
			return new WP_Error( 'pdock_nothing_to_undo', __( 'No snapshots found for this batch. It may have been undone or pruned.', 'pressdock' ), array( 'status' => 404 ) );
		}
		$result['batch_id'] = $batch_id;
		$result['cursor']   = Changes::max_id();
		return new WP_REST_Response( $result );
	}

	/**
	 * GET /media?id=.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function media_get( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'pdock_not_found', __( 'Attachment not found.', 'pressdock' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) && ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'pdock_forbidden', __( 'You are not allowed to read this attachment.', 'pressdock' ), array( 'status' => 403 ) );
		}
		return new WP_REST_Response( self::media_payload( $id ) );
	}

	/**
	 * POST /media (multipart, field name "file").
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function media_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Application Password authenticated REST request.
		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'pdock_no_file', __( 'Send the file as multipart field "file".', 'pressdock' ), array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'pdock_upload_failed', $id->get_error_message(), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( self::media_payload( (int) $id ), 201 );
	}

	/**
	 * Attachment description.
	 *
	 * @param int $id Attachment id.
	 * @return array
	 */
	private static function media_payload( $id ) {
		$file = get_attached_file( $id );
		return array(
			'id'       => $id,
			'url'      => wp_get_attachment_url( $id ),
			'mime'     => get_post_mime_type( $id ),
			'title'    => get_the_title( $id ),
			'filesize' => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}
}
