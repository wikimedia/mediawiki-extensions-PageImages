<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die;
}

define( 'PAGE_IMAGES_INSTALLED', true );

$wgExtensionCredits['api'][] = array(
	'path'           => __FILE__,
	'name'           => 'PageImages',
	'descriptionmsg' => 'pageimages-desc',
	'author'         => 'Max Semenik',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:PageImages'
);

$wgAutoloadClasses['ApiQueryPageImages'] = __DIR__ . "/ApiQueryPageImages.php";
$wgAutoloadClasses['PageImages'] = __DIR__ . "/PageImages.body.php";

$wgMessagesDirs['PageImages'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['PageImages'] = __DIR__ . "/PageImages.i18n.php";

$wgHooks['ParserMakeImageParams'][] = 'PageImages::onParserMakeImageParams';
$wgHooks['LinksUpdate'][] = 'PageImages::onLinksUpdate';
$wgHooks['OpenSearchXml'][] = 'PageImages::onOpenSearchXml';
$wgHooks['InfoAction'][] = 'PageImages::onInfoAction';

$wgAPIPropModules['pageimages'] = 'ApiQueryPageImages';

/**
 * Configures how various aspects of image affect its score
 */
$wgPageImagesScores = array(
	/** position of image in article */
	'position' => array( 8, 6, 4, 3 ),
	/** image width */
	'width' => array(
		99 => -100, // Very small images are usually from maintenace or stub templates
		300 => 10,
		500 => 5, // Larger images are panoramas, less suitable
		501 => 0,
	),
	/** width/height ratio, in tenths */
	'ratio' => array(
		3 => -100,
		5 => 0,
		20 => 5,
		30 => 0,
		31 => -100,
	),
);

$wgPageImagesBlacklist = array(
	array(
		'type' => 'db',
		'page' => 'MediaWiki:Pageimages-blacklist',
		'db' => false, // current wiki
	),
	/*
	array(
		'type' => 'db',
		'page' => 'MediaWiki:Pageimages-blacklist',
		'db' => 'commonswiki',
	),
	array(
		'type' => 'url',
		'url' => 'http://example.com/w/index.php?title=somepage&action=raw',
	),
	 */
);

/**
 * How long blacklist cache lives
 */
$wgPageImagesBlacklistExpiry = 60 * 15;

/**
 * Whether this extension's image information should be used by OpenSearchXml
 */
$wgPageImagesExpandOpenSearchXml = false;

/**
 * Collect data only for these namespaces
 */
$wgPageImagesNamespaces = array( NS_MAIN );
