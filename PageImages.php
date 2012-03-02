<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die;
}

$wgExtensionCredits['api'][] = array(
	'path'           => __FILE__,
	'name'           => 'PageImages',
	'descriptionmsg' => 'pageimages-desc',
	'author'         => 'Max Semenik',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:PageImages'
);

$dir = dirname( __FILE__ );
$wgAutoloadClasses['ApiQueryPageImages'] = "$dir/ApiQueryPageImages.php";
$wgAutoloadClasses['PageImages'] = "$dir/PageImages.body.php";

$wgHooks['ParserMakeImageParams'][] = 'PageImages::onParserMakeImageParams';
$wgHooks['LinksUpdate'][] = 'PageImages::onLinksUpdate';

$wgAPIPropModules['pageimages'] = 'ApiQueryPageImages';

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

$wgPageImagesBlacklist = array(
	array(
		'type' => 'db',
		'page' => 'MediaWiki:Pageimages-blackist',
		'db' => false, // current wiki
	),
	/*
	array(
		'type' => 'db',
		'page' => 'MediaWiki:Pageimages-blackist',
		'db' => 'commonswiki',
	),
	array(
		'type' => 'url',
		'url' => 'http://example.com/w/index.php?title=somepage&action=raw',
	),
	 */
);

$wgPageImagesBlacklistExpiry = 60 * 15;
