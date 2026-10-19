<?php

namespace PageImages\Hooks;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Hook\ParserTestGlobalsHook;
use MediaWiki\Parser\Hook\ParserAfterTidyHook;
use MediaWiki\Parser\Hook\ParserModifyImageHTMLHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use PageImages\PageImageCandidate;
use PageImages\PageImageCandidateProcessor;
use PageImages\PageImages;

/**
 * Handlers for parser hooks.
 *
 * The ParserModifyImageHTML hook handler collects candidate images, and marks
 * them with a temporary HTML comment in the parser output.
 *
 * The ParserAfterTidy hook handler processes the candidate images, identifying
 * the best image and the best free image. If $wgPageImagesLeadSectionOnly is
 * set, images following the first section header are discarded. It removes the
 * temporary comments and saves the resulting best images to page_props.
 *
 * The various query interfaces will retrieve the lead image from page_props.
 *
 * @license WTFPL
 * @author Max Semenik
 * @author Thiemo Kreuz
 */
class ParserFileProcessingHookHandlers implements
	ParserAfterTidyHook,
	ParserModifyImageHTMLHook,
	ParserTestGlobalsHook
{
	private const CANDIDATE_REGEX = '/<!--MW-PAGEIMAGES-CANDIDATE-([0-9]+)-->/';

	public function __construct(
		private readonly PageImageCandidateProcessor $candidateProcessor,
	) {
	}

	/**
	 * @param array &$globals
	 */
	public function onParserTestGlobals( &$globals ) {
		$globals += [
			'wgPageImagesScores' => [
				'width' => [
					200 => 10,
					1000 => 20
				],
				'position' => [],
				'ratio' => [],
				'galleryImageWidth' => []
			],
			'wgPageImagesLeadSectionOnly' => true
		];
	}

	/**
	 * ParserModifyImageHTML hook. Save candidate images, and mark them with a
	 * comment so that we can later tell if they were in the lead section.
	 */
	public function onParserModifyImageHTML(
		Parser $parser,
		File $file,
		array $params,
		string &$html
	): void {
		if ( !$this->candidateProcessor->processThisTitle( $parser->getPage() ) ) {
			return;
		}

		$this->candidateProcessor->calcWidth( $params, $file );

		$index = $this->addPageImageCandidateToParserOutput(
			PageImageCandidate::newFromFileAndParams( $file, $params ),
			$parser->getOutput()
		);
		$html .= "<!--MW-PAGEIMAGES-CANDIDATE-$index-->";
	}

	/**
	 * ParserAfterTidy hook handler. Remove candidate images which were not in
	 * the lead section.
	 *
	 * @param Parser $parser
	 * @param string &$text
	 */
	public function onParserAfterTidy( $parser, &$text ) {
		$parserOutput = $parser->getOutput();
		$allImages = $parserOutput->getExtensionData( 'pageImages' );
		if ( !$allImages ) {
			return;
		}

		// Find and remove our special comments
		$images = [];
		if ( $this->candidateProcessor->isLeadSectionOnly() ) {
			$leadEndPos = strpos( $text, '<mw:editsection' );
		} else {
			$leadEndPos = false;
		}
		$text = preg_replace_callback(
			self::CANDIDATE_REGEX,
			static function ( $m ) use ( $allImages, &$images, $leadEndPos ) {
				$offset = $m[0][1];
				$id = intval( $m[1][0] );
				$inLead = $leadEndPos === false || $offset < $leadEndPos;
				if ( $inLead && isset( $allImages[$id] ) ) {
					$images[] = PageImageCandidate::newFromArray( $allImages[$id] );
				}
				return '';
			},
			$text, -1, $count, PREG_OFFSET_CAPTURE
		);

		[ $bestImageName, $freeImageName ] = $this->candidateProcessor->findBestImages( $images );

		if ( $freeImageName ) {
			$parserOutput->setPageProperty( PageImages::getPropName( true ), $freeImageName );
		}

		// Only store the image if it's not free. Free image (if any) has already been stored above.
		if ( $bestImageName && $bestImageName !== $freeImageName ) {
			$parserOutput->setPageProperty( PageImages::getPropName( false ), $bestImageName );
		}

		// Strip comments from indicators (T298930)
		foreach ( $parserOutput->getIndicators() as $id => $value ) {
			$stripped = preg_replace( self::CANDIDATE_REGEX, '', $value );
			if ( $stripped !== $value ) {
				$parserOutput->setIndicator( $id, $stripped );
			}
		}
		// We may have comments in TOC data - Parser::cleanupTocLine strips them for us.
	}

	/**
	 * Adds $image to $parserOutput extension data.
	 */
	private function addPageImageCandidateToParserOutput(
		PageImageCandidate $image,
		ParserOutput $parserOutput
	): int {
		$images = $parserOutput->getExtensionData( 'pageImages' ) ?: [];
		$images[] = $image->jsonSerialize();
		$parserOutput->setExtensionData( 'pageImages', $images );
		return count( $images ) - 1;
	}

}
