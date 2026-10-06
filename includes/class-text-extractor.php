<?php
/**
 * Turns an uploaded document into plain text.
 *
 * @package BlueBranch\Chatbot
 */

namespace BlueBranch\Chatbot;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads text out of the file formats the additional content accepts.
 *
 * The conversion happens here, on the site, and only the text leaves it. The
 * API never receives a file, a file name it could fetch or anything binary --
 * which is also what keeps a stray macro or embedded script in a document
 * from ever reaching it.
 */
class Text_Extractor {

	/**
	 * Upper bound for the text handed on, in characters.
	 */
	const MAX_CHARS = 200000;

	/**
	 * Largest file read at all, in bytes.
	 */
	const MAX_BYTES = 20 * MB_IN_BYTES;

	/**
	 * Accepted extensions and the MIME types the media library knows them by.
	 *
	 * @return array<string, string>
	 */
	public static function mime_types() {
		return array(
			'txt'  => 'text/plain',
			'md'   => 'text/markdown',
			'csv'  => 'text/csv',
			'htm'  => 'text/html',
			'html' => 'text/html',
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'odt'  => 'application/vnd.oasis.opendocument.text',
		);
	}

	/**
	 * Extracts the text of a media library attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|WP_Error
	 */
	public function from_attachment( $attachment_id ) {
		$path = get_attached_file( (int) $attachment_id );

		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'The file could not be found.', 'bluebranch-chatbot' ) );
		}

		return $this->from_file( $path );
	}

	/**
	 * Extracts the text of a file on disk.
	 *
	 * @param string $path Absolute path.
	 * @return string|WP_Error
	 */
	public function from_file( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, self::mime_types() ) ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'This file type is not supported.', 'bluebranch-chatbot' ) );
		}

		if ( filesize( $path ) > self::MAX_BYTES ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'The file is larger than 20 MB.', 'bluebranch-chatbot' ) );
		}

		switch ( $extension ) {
			case 'pdf':
				$text = $this->pdf( $path );
				break;
			case 'docx':
				$text = $this->office( $path, 'word/document.xml' );
				break;
			case 'odt':
				$text = $this->office( $path, 'content.xml' );
				break;
			case 'htm':
			case 'html':
				$text = $this->html( $this->read( $path ) );
				break;
			default:
				$text = $this->read( $path );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = $this->normalise( $text );

		if ( '' === $text ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'No text could be read from the file. Scanned documents need to be run through text recognition first.', 'bluebranch-chatbot' ) );
		}

		return $text;
	}

	/**
	 * Tidies text from any source and cuts it to the upper bound.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public function normalise( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = (string) preg_replace( "/ *\n */", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = trim( $text );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text, 'UTF-8' ) > self::MAX_CHARS ) {
			$text = mb_substr( $text, 0, self::MAX_CHARS, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Reads a text file and converts it to UTF-8.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function read( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file from the uploads directory.
		$raw = (string) file_get_contents( $path );

		// Byte order mark.
		if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}

		return $raw;
	}

	/**
	 * Strips markup, keeping block boundaries as line breaks.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function html( $html ) {
		$html = (string) preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html );

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Reads the text out of a PDF.
	 *
	 * @param string $path Absolute path.
	 * @return string|WP_Error
	 */
	private function pdf( $path ) {
		$autoload = BLUEBRANCH_CHATBOT_DIR . 'vendor/autoload.php';

		// Loaded here and only here: the plugin's own classes do not need
		// Composer, and an autoloader on every request could collide with
		// another plugin's copy of the same library.
		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) && is_readable( $autoload ) ) {
			require_once $autoload;
		}

		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'PDF files cannot be read on this installation (the PDF library is missing).', 'bluebranch-chatbot' ) );
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();

			return (string) $parser->parseFile( $path )->getText();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'bluebranch_chatbot_file',
				sprintf(
					/* translators: %s: error message from the PDF library. */
					__( 'The PDF could not be read: %s', 'bluebranch-chatbot' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Reads the text out of a DOCX or ODT file.
	 *
	 * Both are zip archives with the body in one XML file. Paragraph and line
	 * break elements become line breaks; everything else is dropped.
	 *
	 * @param string $path  Absolute path.
	 * @param string $entry File inside the archive holding the body.
	 * @return string|WP_Error
	 */
	private function office( $path, $entry ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'Office documents cannot be read on this server (the zip extension is missing).', 'bluebranch-chatbot' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'The document could not be opened.', 'bluebranch-chatbot' ) );
		}

		$xml = $zip->getFromName( $entry );
		$zip->close();

		if ( ! is_string( $xml ) || '' === $xml ) {
			return new WP_Error( 'bluebranch_chatbot_file', __( 'The document could not be opened.', 'bluebranch-chatbot' ) );
		}

		$xml = (string) preg_replace( '#<(w:p|text:p|text:h)\b[^>]*/>#', "\n", $xml );
		$xml = (string) preg_replace( '#</(w:p|text:p|text:h)>#', "\n", $xml );
		$xml = (string) preg_replace( '#<(w:br|w:cr|text:line-break)\b[^>]*/?>#', "\n", $xml );
		$xml = (string) preg_replace( '#<(w:tab|text:tab)\b[^>]*/?>#', "\t", $xml );

		return html_entity_decode( wp_strip_all_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
