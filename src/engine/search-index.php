<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the records behind the static search (a Fuse.js index file or Algolia).
 */
class Search_Index {

	const MAX_TEXT = 5000;

	/** Tags and classes that are not page content. */
	const STRIP_TAGS    = array( 'script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'svg', 'iframe', 'template' );
	const STRIP_CLASSES = array( 'ss-search-panel', 'sidebar', 'widget' );

	/**
	 * @param string $path Export-relative file path.
	 * @return string URL path.
	 */
	public static function file_path_to_url( $path ) {
		if ( 'index.html' === $path ) {
			return '/';
		}
		if ( '/index.html' === substr( '/' . $path, -11 ) ) {
			return '/' . substr( $path, 0, -10 );
		}

		return '/' . $path;
	}

	/**
	 * WordPress listing pages: taxonomy, author and date archives and /page/N/ pagination.
	 *
	 * @param string $url URL path.
	 * @return bool
	 */
	public static function is_archive_url( $url ) {
		return (bool) ( preg_match( '#/(category|tag|author)/[^/]+/(page/\d+/)?$#', $url )
			|| preg_match( '#(^|/)page/\d+/$#', $url )
			|| preg_match( '#^/\d{4}/(\d{2}/)?(\d{2}/)?$#', $url ) );
	}

	/**
	 * @param string $url URL path for Algolia.
	 * @return string 20 hex chars.
	 */
	public static function object_id( $url ) {
		return substr( sha1( $url ), 0, 20 );
	}

	/**
	 * @param string $html      Page HTML.
	 * @param string $file_path Export-relative path.
	 * @return array|null url, title, text, lang? or null when the page should not be searchable.
	 */
	public static function extract_record( $html, $file_path ) {
		if ( preg_match( '/<meta\b[^>]*name=["\']robots["\'][^>]*content=["\'][^"\']*noindex/i', $html ) || preg_match( '/<meta\b[^>]*content=["\'][^"\']*noindex[^"\']*["\'][^>]*name=["\']robots["\']/i', $html ) ) {
			return null;
		}
		$dom = Fixes::dom( $html );
		if ( ! $dom ) {
			return null;
		}
		$xp = new \DOMXPath( $dom );

		$title = '';
		$node  = $xp->query( '//title' )->item( 0 );
		if ( $node ) {
			$title = $node->textContent;
		} else {
			$h1 = $xp->query( '//h1' )->item( 0 );
			$title = $h1 ? $h1->textContent : '';
		}
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );

		$main = null;
		foreach ( array( '//main', '//article', '//*[@role="main"]', '//*[@id="content"]', '//body' ) as $q ) {
			$found = $xp->query( $q );
			if ( $found && $found->length ) {
				$main = $found->item( 0 );
				break;
			}
		}
		if ( ! $main ) {
			$main = $dom->documentElement;
		}
		if ( ! $main ) {
			return null;
		}

		$conditions = array();
		foreach ( self::STRIP_TAGS as $tag ) {
			$conditions[] = './/' . $tag;
		}
		$conditions[] = './/*[@hidden]';
		foreach ( self::STRIP_CLASSES as $class ) {
			$conditions[] = ".//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
		}
		$remove = array();
		foreach ( $xp->query( implode( '|', $conditions ), $main ) as $n ) {
			$remove[] = $n;
		}
		foreach ( $remove as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}

		// Text nodes of neighbouring elements would otherwise be glued together ("<h1>A</h1><p>B</p>" -> "AB").
		$markup = $dom->saveHTML( $main );
		$markup = preg_replace( '~</?(p|div|h[1-6]|li|ul|ol|br|section|article|tr|td|th|table|blockquote|figure|figcaption|dd|dt)\b[^>]*>~i', ' ', (string) $markup );
		$text   = html_entity_decode( strip_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text   = trim( preg_replace( '/\s+/u', ' ', $text ) );
		$text   = mb_substr( $text, 0, self::MAX_TEXT );
		if ( '' === $title && '' === $text ) {
			return null;
		}

		$record = array( 'url' => self::file_path_to_url( $file_path ), 'title' => $title, 'text' => $text );
		$lang   = $dom->documentElement ? $dom->documentElement->getAttribute( 'lang' ) : '';
		if ( '' !== $lang ) {
			$record['lang'] = $lang;
		}

		return $record;
	}

	/**
	 * @param array    $cfg     appId, adminKey, index.
	 * @param string   $path    Path below the index URL ('' for the index itself).
	 * @param string   $method  Method.
	 * @param array    $body    JSON body.
	 * @return Response
	 */
	protected static function algolia( array $cfg, $path, $method, array $body ) {
		$url = 'https://' . $cfg['appId'] . '.algolia.net/1/indexes/' . rawurlencode( $cfg['index'] ) . $path;

		return Http::request(
			$method,
			$url,
			array( 'X-Algolia-Application-Id' => $cfg['appId'], 'X-Algolia-API-Key' => $cfg['adminKey'], 'Content-Type' => 'application/json' ),
			json_encode( $body ? $body : new \stdClass() )
		);
	}

	/**
	 * Prepare the index: settings, then clear it.
	 *
	 * @param array $cfg appId, adminKey, index.
	 * @return void
	 */
	public static function algolia_begin( array $cfg ) {
		Util::required( $cfg, array( 'appId', 'adminKey', 'index' ), 'algolia' );
		self::algolia( $cfg, '/settings', 'PUT', array( 'searchableAttributes' => array( 'title', 'unordered(text)' ), 'attributesToSnippet' => array( 'text:30' ), 'attributesToHighlight' => array( 'title', 'text' ) ) );
		self::algolia( $cfg, '/clear', 'POST', array() );
	}

	/**
	 * @param array   $cfg     appId, adminKey, index.
	 * @param array[] $records Up to a few hundred records.
	 * @return void
	 */
	public static function algolia_batch( array $cfg, array $records ) {
		$requests = array();
		foreach ( $records as $r ) {
			$requests[] = array( 'action' => 'updateObject', 'body' => array( 'objectID' => self::object_id( $r['url'] ) ) + $r );
		}
		self::algolia( $cfg, '/batch', 'POST', array( 'requests' => $requests ) );
	}
}
