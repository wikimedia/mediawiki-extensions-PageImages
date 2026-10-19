<?php

use MediaWiki\MediaWikiServices;
use PageImages\PageImageCandidateProcessor;
use PageImages\PageImages;

/** @phpcs-require-sorted-array */
return [
	'PageImages.PageImageCandidateProcessor' => static function (
		MediaWikiServices $services
	): PageImageCandidateProcessor {
		return new PageImageCandidateProcessor(
			$services->getMainConfig(),
			$services->getRepoGroup(),
			$services->getMainWANObjectCache(),
			$services->getHttpRequestFactory(),
			$services->getDBLoadBalancerFactory(),
			$services->getTitleFactory(),
			$services->getLinksMigration()
		);
	},
	'PageImages.PageImages' => static function ( MediaWikiServices $services ): PageImages {
		return new PageImages(
			$services->getMainConfig(),
			$services->getConnectionProvider(),
			$services->getRepoGroup(),
			$services->getUrlUtils(),
			$services->getUserOptionsLookup()
		);
	},
];
