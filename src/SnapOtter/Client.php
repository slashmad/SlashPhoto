<?php
/**
 * HTTP client for a SnapOtter server.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\SnapOtter;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the REST API of a self-hosted SnapOtter server
 * (https://github.com/snapotter-hq/SnapOtter, AGPL-3.0). SlashPhoto only
 * calls it over HTTP; no SnapOtter code is included.
 *
 * Requests go only to the address the site administrator entered. Image
 * files are sent there and nowhere else.
 */
final class Client {

	public const TIMEOUT = 300;

	/**
	 * Server address without trailing slash.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Bearer token (API key or session token).
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor.
	 *
	 * @param string $url   Server address.
	 * @param string $token API key or session token.
	 */
	public function __construct( string $url, string $token = '' ) {
		$this->url   = untrailingslashit( $url );
		$this->token = $token;
	}

	/**
	 * Signs in and returns a session token.
	 *
	 * @param string $username User name.
	 * @param string $password Password.
	 * @return string|WP_Error
	 */
	public function login( string $username, string $password ) {
		$data = $this->json(
			'POST',
			'/api/auth/login',
			[
				'username' => $username,
				'password' => $password,
			]
		);
		if ( is_wp_error( $data ) ) {
			return 401 === (int) ( $data->get_error_data()['status'] ?? 0 )
				? new WP_Error( 'slashphoto_snapotter_login', __( 'SnapOtter did not accept the user name or password.', 'slashphoto' ) )
				: $data;
		}
		if ( ! empty( $data['requiresMfa'] ) ) {
			return new WP_Error( 'slashphoto_snapotter_mfa', __( 'This SnapOtter account uses two-factor sign-in. Create an API key in SnapOtter (Settings → API Keys) and connect with the key instead.', 'slashphoto' ) );
		}
		if ( empty( $data['token'] ) || ! is_string( $data['token'] ) ) {
			return new WP_Error( 'slashphoto_snapotter_response', __( 'SnapOtter answered in an unexpected way.', 'slashphoto' ) );
		}
		return $data['token'];
	}

	/**
	 * Ends the session of the current token. Errors are ignored.
	 */
	public function logout(): void {
		$this->json( 'POST', '/api/auth/logout', [] );
	}

	/**
	 * Creates an API key with the current session.
	 *
	 * @param string $name Key name shown in SnapOtter.
	 * @return array{key: string, id: string}|WP_Error
	 */
	public function create_key( string $name ) {
		$data = $this->json( 'POST', '/api/v1/api-keys', [ 'name' => $name ] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['key'] ) || ! is_string( $data['key'] ) ) {
			return new WP_Error( 'slashphoto_snapotter_response', __( 'SnapOtter did not return an API key.', 'slashphoto' ) );
		}
		return [
			'key' => $data['key'],
			'id'  => (string) ( $data['id'] ?? '' ),
		];
	}

	/**
	 * Deletes an API key. Errors are ignored.
	 *
	 * @param string $id Key ID.
	 */
	public function delete_key( string $id ): void {
		$this->json( 'DELETE', '/api/v1/api-keys/' . rawurlencode( $id ), null );
	}

	/**
	 * Checks that the server is healthy and the key is accepted.
	 *
	 * @return string|WP_Error SnapOtter version.
	 */
	public function check() {
		$health = $this->json( 'GET', '/api/v1/health', null );
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		if ( 'healthy' !== ( $health['status'] ?? '' ) ) {
			return new WP_Error( 'slashphoto_snapotter_unhealthy', __( 'SnapOtter reports that it is not healthy.', 'slashphoto' ) );
		}
		// A tools listing needs a valid key.
		$tools = $this->json( 'GET', '/api/v1/pipeline/tools', null );
		if ( is_wp_error( $tools ) ) {
			return in_array( (int) ( $tools->get_error_data()['status'] ?? 0 ), [ 401, 403 ], true )
				? new WP_Error( 'slashphoto_snapotter_key', __( 'SnapOtter did not accept the API key, or the key may not run tools.', 'slashphoto' ) )
				: $tools;
		}
		return (string) ( $health['version'] ?? '' );
	}

	/**
	 * Makes the display copy of an image: decodes it (RAW, HEIC, TIFF, PSD,
	 * JPEG, …), turns it upright, scales it to fit a square of $long_edge
	 * pixels without enlarging, and saves a JPEG with the original's colour
	 * profile and metadata at $target.
	 *
	 * @param string $source    Original file.
	 * @param int    $long_edge Longest side of the result in pixels.
	 * @param int    $quality   JPEG quality (1-100).
	 * @param string $target    Where to save the JPEG.
	 * @return true|WP_Error
	 */
	public function display_copy( string $source, int $long_edge, int $quality, string $target ) {
		// optimize-for-web keeps the colour profile, EXIF and XMP when asked
		// to (resize and convert drop them) and applies EXIF orientation.
		$pipeline = [
			'steps' => [
				[
					'toolId'   => 'optimize-for-web',
					'settings' => [
						'format'        => 'jpeg',
						'quality'       => $quality,
						'maxWidth'      => $long_edge,
						'maxHeight'     => $long_edge,
						'stripMetadata' => false,
					],
				],
			],
		];

		$result = $this->upload( '/api/v1/pipeline/execute', $source, [ 'pipeline' => (string) wp_json_encode( $pipeline ) ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$download = $this->download_url( $result );
		if ( is_wp_error( $download ) ) {
			return $download;
		}
		return $this->download( $download, $target );
	}

	/**
	 * Finds the result URL, waiting for jobs that run in the background.
	 *
	 * @param array $result Response of a tool or pipeline request.
	 * @return string|WP_Error
	 */
	private function download_url( array $result ) {
		if ( ! empty( $result['downloadUrl'] ) && is_string( $result['downloadUrl'] ) ) {
			return $result['downloadUrl'];
		}
		if ( empty( $result['jobId'] ) || ! is_string( $result['jobId'] ) ) {
			return new WP_Error( 'slashphoto_snapotter_response', __( 'SnapOtter answered in an unexpected way.', 'slashphoto' ) );
		}

		// Server-sent events; the stream ends when the job is done.
		$response = wp_remote_get(
			$this->url . '/api/v1/jobs/' . rawurlencode( $result['jobId'] ) . '/progress',
			[
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers( [ 'Accept' => 'text/event-stream' ] ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$url   = '';
		$error = '';
		foreach ( preg_split( '/\r?\n/', (string) wp_remote_retrieve_body( $response ) ) as $line ) {
			if ( ! str_starts_with( $line, 'data:' ) ) {
				continue;
			}
			$event = json_decode( trim( substr( $line, 5 ) ), true );
			if ( ! is_array( $event ) ) {
				continue;
			}
			$url   = (string) ( $event['result']['downloadUrl'] ?? $event['downloadUrl'] ?? $url );
			$error = (string) ( $event['error'] ?? $error );
		}
		if ( '' !== $url ) {
			return $url;
		}
		return new WP_Error( 'slashphoto_snapotter_job', '' !== $error ? $error : __( 'SnapOtter did not finish the image.', 'slashphoto' ) );
	}

	/**
	 * Streams a result to a file.
	 *
	 * @param string $path   Path or absolute URL from SnapOtter.
	 * @param string $target Destination file.
	 * @return true|WP_Error
	 */
	private function download( string $path, string $target ) {
		$url = preg_match( '#^https?://#i', $path ) ? $path : $this->url . '/' . ltrim( $path, '/' );
		// The API key is sent along, so never follow a link to another server.
		$own = wp_parse_url( $this->url );
		$got = wp_parse_url( $url );
		if ( ! $own || ! $got || strtolower( (string) ( $own['host'] ?? '' ) ) !== strtolower( (string) ( $got['host'] ?? '' ) ) || ( $own['port'] ?? null ) !== ( $got['port'] ?? null ) || ( $own['scheme'] ?? '' ) !== ( $got['scheme'] ?? '' ) ) {
			return new WP_Error( 'slashphoto_snapotter_response', __( 'SnapOtter answered with a link to another server; it was not followed.', 'slashphoto' ) );
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout'  => self::TIMEOUT,
				'headers'  => $this->headers(),
				'stream'   => true,
				'filename' => $target,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! is_file( $target ) || ! filesize( $target ) ) {
			wp_delete_file( $target );
			/* translators: %d: HTTP status code */
			return new WP_Error( 'slashphoto_snapotter_download', sprintf( __( 'Could not download the result from SnapOtter (HTTP %d).', 'slashphoto' ), $code ) );
		}
		return true;
	}

	/**
	 * Sends a file as multipart/form-data. With the cURL transport the file
	 * is streamed from disk, so large originals do not have to fit in PHP's
	 * memory.
	 *
	 * @param string               $path   API path.
	 * @param string               $file   File to send in the "file" field.
	 * @param array<string,string> $fields Other form fields.
	 * @return array|WP_Error Decoded JSON.
	 */
	private function upload( string $path, string $file, array $fields ) {
		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'slashphoto_snapotter_file', __( 'The uploaded file could not be read.', 'slashphoto' ) );
		}

		$curl_fields = $fields;
		$use_curl    = class_exists( '\CURLFile' );
		if ( $use_curl ) {
			$type                = (string) wp_check_filetype( $file )['type'];
			$curl_fields['file'] = new \CURLFile( $file, '' !== $type ? $type : 'application/octet-stream', wp_basename( $file ) );
		}
		$hook = static function ( $handle, $request, $url ) use ( $curl_fields, $path ) {
			if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- streams the file instead of reading it into memory.
				curl_setopt( $handle, CURLOPT_POSTFIELDS, $curl_fields );
			}
		};

		$args = [
			'method'  => 'POST',
			'timeout' => self::TIMEOUT,
			'headers' => $this->headers(),
		];
		if ( $use_curl ) {
			// Placeholder body; replaced by the multipart fields in the cURL hook.
			$args['body'] = $fields;
			add_action( 'http_api_curl', $hook, 10, 3 );
		} else {
			$boundary                        = 'slashphoto' . bin2hex( random_bytes( 12 ) );
			$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$args['body']                    = self::multipart( $boundary, $fields, $file );
		}

		$response = wp_remote_request( $this->url . $path, $args );
		if ( $use_curl ) {
			remove_action( 'http_api_curl', $hook, 10 );
		}
		return $this->decode( $response );
	}

	/**
	 * Builds a multipart body in memory (fallback without cURL).
	 *
	 * @param string               $boundary Boundary.
	 * @param array<string,string> $fields   Fields.
	 * @param string               $file     File for the "file" field.
	 */
	private static function multipart( string $boundary, array $fields, string $file ): string {
		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . str_replace( '"', '', wp_basename( $file ) ) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n";
		$body .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return $body . "\r\n--{$boundary}--\r\n";
	}

	/**
	 * JSON request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error
	 */
	private function json( string $method, string $path, ?array $body ) {
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => $this->headers( null === $body ? [] : [ 'Content-Type' => 'application/json' ] ),
		];
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( (object) $body );
		}
		return $this->decode( wp_remote_request( $this->url . $path, $args ) );
	}

	/**
	 * Decodes a JSON response, turning HTTP errors into WP_Error.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'slashphoto_snapotter_unreachable', sprintf( __( 'Could not reach SnapOtter: %s', 'slashphoto' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];
		if ( $code < 200 || $code >= 300 ) {
			$message = (string) ( $data['error'] ?? $data['message'] ?? '' );
			return new WP_Error(
				'slashphoto_snapotter_http',
				/* translators: 1: HTTP status code, 2: message from SnapOtter */
				sprintf( __( 'SnapOtter answered with an error (HTTP %1$d). %2$s', 'slashphoto' ), $code, $message ),
				[ 'status' => $code ]
			);
		}
		return $data;
	}

	/**
	 * Request headers.
	 *
	 * @param array<string,string> $extra Extra headers.
	 * @return array<string,string>
	 */
	private function headers( array $extra = [] ): array {
		$headers = [ 'Accept' => 'application/json' ] + $extra;
		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}
}
