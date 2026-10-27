<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the WordPress comment form with Giscus, Utterances or Disqus (or removes it).
 * Existing comments are part of the page HTML and stay as they are.
 */
class Comments_Enhancer {

	/**
	 * @param string $html HTML.
	 * @return string
	 */
	public static function process( $html ) {
		$provider = (string) Options::instance()->get( 'comments_provider' );
		if ( '' === $provider ) {
			return self::strip_login_prompts( $html );
		}
		if ( false === stripos( $html, 'id="respond"' ) && false === stripos( $html, "id='respond'" ) ) {
			return $html;
		}

		$replacement = self::widget( $provider );
		if ( null === $replacement ) {
			return $html;
		}

		$extent = Html_Enhancer::find_element( $html, '/<div\b[^>]*\bid=["\']respond["\'][^>]*>/i', 'div' );
		if ( ! $extent ) {
			return $html;
		}
		$html = substr_replace( $html, $replacement, $extent[0], $extent[1] - $extent[0] );

		// "Reply" links point at ?replytocom=, which does nothing on a static site.
		return preg_replace( '#<div class="reply">.*?</div>#is', '', $html );
	}

	/**
	 * Without a comment provider the WordPress form stays, but "You must be logged in to post a comment"
	 * and "Log in to Reply" link to wp-login.php, which does not exist on a static host. Remove those prompts,
	 * and the "Leave a Reply" block when nothing but the prompt was in it.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function strip_login_prompts( $html ) {
		if ( false === stripos( $html, 'wp-login.php' ) ) {
			return $html;
		}
		$html = preg_replace( '#<p class="must-log-in">.*?</p>#is', '', $html );
		$html = preg_replace( '#<a\b[^>]*\bclass=["\'][^"\']*comment-reply-login[^"\']*["\'][^>]*>.*?</a>#is', '', $html );
		$html = preg_replace( '#<div class="reply">\s*</div>#i', '', $html );

		$extent = Html_Enhancer::find_element( $html, '/<div\b[^>]*\bid=["\']respond["\'][^>]*>/i', 'div' );
		if ( $extent ) {
			$block = substr( $html, $extent[0], $extent[1] - $extent[0] );
			if ( false === stripos( $block, '<form' ) ) {
				$html = substr_replace( $html, '', $extent[0], $extent[1] - $extent[0] );
			}
		}

		return $html;
	}

	/**
	 * @param string $provider Provider id.
	 * @return string|null Markup, or null when the provider is not configured.
	 */
	public static function widget( $provider ) {
		$o     = Options::instance();
		$theme = (string) ( $o->get( 'comments_theme' ) ?: 'preferred_color_scheme' );

		switch ( $provider ) {
			case 'remove':
				return '';

			case 'giscus':
				$repo = (string) $o->get( 'comments_giscus_repo' );
				if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) || ! $o->get( 'comments_giscus_repo_id' ) ) {
					return null;
				}

				return '<div class="ss-comments">' . self::script_tag(
					'https://giscus.app/client.js',
					array(
						'data-repo'              => $repo,
						'data-repo-id'           => (string) $o->get( 'comments_giscus_repo_id' ),
						'data-category'          => (string) $o->get( 'comments_giscus_category' ),
						'data-category-id'       => (string) $o->get( 'comments_giscus_category_id' ),
						'data-mapping'           => 'pathname',
						'data-strict'            => '0',
						'data-reactions-enabled' => '1',
						'data-emit-metadata'     => '0',
						'data-input-position'    => 'bottom',
						'data-theme'             => $theme,
						'data-lang'              => 'en',
						'crossorigin'            => 'anonymous',
					)
				) . '</div>';

			case 'utterances':
				$repo = (string) $o->get( 'comments_giscus_repo' );
				if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
					return null;
				}
				$u_theme = in_array( $theme, array( 'github-light', 'github-dark', 'preferred-color-scheme', 'github-dark-orange', 'icy-dark', 'dark-blue', 'photon-dark', 'boxy-light', 'gruvbox-dark' ), true ) ? $theme : 'preferred-color-scheme';

				return '<div class="ss-comments">' . self::script_tag(
					'https://utteranc.es/client.js',
					array(
						'repo'       => $repo,
						'issue-term' => 'pathname',
						'theme'      => $u_theme,
						'crossorigin' => 'anonymous',
					)
				) . '</div>';

			case 'disqus':
				$short = (string) $o->get( 'comments_disqus_shortname' );
				if ( ! preg_match( '/^[a-z0-9-]+$/i', $short ) ) {
					return null;
				}

				return '<div class="ss-comments"><div id="disqus_thread"></div><script>'
					. 'var disqus_config=function(){this.page.url=location.href.split("#")[0];this.page.identifier=location.pathname;};'
					. '(function(){var d=document,s=d.createElement("script");s.src="https://' . $short . '.disqus.com/embed.js";'
					. 's.setAttribute("data-timestamp",+new Date());(d.head||d.body).appendChild(s);})();</script></div>';
		}

		return null;
	}

	/**
	 * @param string $src   Script URL.
	 * @param array  $attrs Extra attributes.
	 * @return string
	 */
	protected static function script_tag( $src, array $attrs ) {
		$out = '<script src="' . esc_url( $src ) . '"';
		foreach ( $attrs as $k => $v ) {
			$out .= ' ' . $k . '="' . esc_attr( $v ) . '"';
		}

		return $out . ' async></script>';
	}
}
