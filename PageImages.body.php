<?php

class PageImages {
	public static function registerImage( Title $title, File $file, &$params, Parser $parser ) {
		$out = $parser->getOutput();
		if ( !isset( $out->pageImages ) ) {
			$out->pageImages = array();
		}
		$myParams = $params;
		if ( !isset( $myParams['handler']['width'] ) ) {
			$myParams['handler']['width'] = $file->getWidth();
		}
		$out->pageImages[$title->getDBkey()] = $myParams;
		return true;
	}

	public static function getProperties( LinksUpdate $lu ) {
		if ( !isset( $lu->getParserOutput()->pageImages ) ) {
			return true;
		}
		$images = $lu->getParserOutput()->pageImages;
		return true;
	}
}
