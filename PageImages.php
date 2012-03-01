<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die;
}

define( 'PAGE_IMAGES_INSTALLED', true );

$dir = dirname( __FILE__ );
$wgAutoloadClasses['PageImages'] = "$dir/PageImages.body.php";

$wgHooks['ParserMakeImageParams'][] = 'PageImages::onParserMakeImageParams';
$wgHooks['LinksUpdate'][] = 'PageImages::onLinksUpdate';

$wgPageImagesScores = array(
	'extension' => array(
		'jpg' => 5,
		'jpeg' => 5,
		'png' => 1,
		'svg' => 1,
	),
	'position' => array( 8, 6, 4, 3 ),
	'width' => array(
		99 => -100, // Very small images are usually from maintenace or stub templates
		300 => 10,
		500 => 5, // Larger images are panoramas, less suitable
	),
);