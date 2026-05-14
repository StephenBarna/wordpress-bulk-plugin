<?php
/**
 * Thin OpenAI Chat Completions wrapper. Uses wp_remote_post so we don't need
 * Composer or any external dependencies for Phase 0.
 *
 * Returns plain text content from a single turn, or WP_Error on failure.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenAI_Client {

	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	private string $api_key;
	private string $model;

	public function __construct( string $api_key, string $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * Send a one-turn user message and return the assistant's text reply.
	 *
	 * @param string $user_prompt   The user message.
	 * @param string $system_prompt Optional system prompt.
	 * @param int    $timeout       Seconds.
	 * @param float  $temperature   Sampling temperature (0.0-2.0). Default 0.2
	 *                              for the rewrite engine - low enough that
	 *                              the model sticks close to the original
	 *                              wording instead of synonym-substituting.
	 * @return string|WP_Error
	 */
	public function chat( string $user_prompt, string $system_prompt = '', int $timeout = 30, float $temperature = 0.2 ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error( 'ehbp_no_key', __( 'No OpenAI API key configured.', 'earthhaul-bulk-pages' ) );
		}

		$messages = array();
		if ( '' !== trim( $system_prompt ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system_prompt,
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_prompt,
		);

		$body = array(
			'model'       => $this->model,
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'HTTP %d', $status );
			return new WP_Error( 'ehbp_openai_http', $message, array( 'status' => $status, 'raw' => $raw ) );
		}

		if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'ehbp_openai_shape', __( 'Unexpected OpenAI response shape.', 'earthhaul-bulk-pages' ), array( 'raw' => $raw ) );
		}

		return trim( (string) $data['choices'][0]['message']['content'] );
	}

	/**
	 * Tiny smoke test - sends a fixed prompt and returns the reply.
	 */
	public function ping( string $prompt = 'Say: pong' ) {
		return $this->chat( $prompt, '', 15 );
	}
}
