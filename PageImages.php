<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die;
}

define( 'PAGE_IMAGES_INSTALLED', true );

$dir = dirname( __FILE__ );
$wgAutoloadClasses['PageImages'] = "$dir/PageImages.body.php";

$wgHooks['ParserMakeImageParams'][] = 'PageImages::registerImage';
$wgHooks['LinksUpdate'][] = 'PageImages::getProperties';
