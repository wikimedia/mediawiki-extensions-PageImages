<?php

namespace PageImages\Tests\Hooks;

use MediaWiki\Config\HashConfig;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWikiIntegrationTestCase;
use PageImages\Hooks\ParserFileProcessingHookHandlers;
use PageImages\PageImageCandidate;
use PageImages\PageImageCandidateProcessor;
use PageImages\PageImages;

/**
 * @covers \PageImages\Hooks\ParserFileProcessingHookHandlers
 *
 * @group PageImages
 *
 * @license WTFPL
 * @author Thiemo Kreuz
 */
class ParserFileProcessingHookHandlersTest extends MediaWikiIntegrationTestCase {

	/**
	 * @param array[] $images
	 * @return Parser
	 */
	private function getParser( array $images ): Parser {
		$parser = $this->getServiceContainer()->getParser();
		$title = PageReferenceValue::localReference( NS_MAIN, 'Test' );
		$options = ParserOptions::newFromAnon();
		$parser->startExternalParse( $title, $options, Parser::OT_HTML );
		$parser->getOutput()->setExtensionData( 'pageImages', $images );
		return $parser;
	}

	/**
	 * @param int[] $indexes
	 * @param int $nonLeadIndex
	 * @return string
	 */
	private function getHtml( array $indexes, $nonLeadIndex = INF ): string {
		$html = '';
		$doneSectionBreak = false;
		foreach ( $indexes as $index ) {
			if ( $index >= $nonLeadIndex && !$doneSectionBreak ) {
				$html .= '<mw:editsection page="Test" section="1"/>';
				$doneSectionBreak = true;
			}
			$html .= "<!--MW-PAGEIMAGES-CANDIDATE-$index-->";
		}
		return $html;
	}

	private function getHandler( array $images, bool $leadOnly = false ): ParserFileProcessingHookHandlers {
		$candidateProcessor = new class ( $images, $leadOnly ) extends PageImageCandidateProcessor {
			private array $isFreeMap;

			public function __construct(
				private readonly array $images,
				bool $leadOnly,
			) {
				$this->config = new HashConfig( [
					'PageImagesLeadSectionOnly' => $leadOnly,
				] );
				foreach ( $images as $image ) {
					$this->isFreeMap[$image['filename']] = $image['isFree'];
				}
			}

			protected function isImageFree( $fileName ): bool {
				return $this->isFreeMap[$fileName] ?? false;
			}

			protected function getScore( PageImageCandidate $image, int $position ) {
				return $this->images[$position]['score'];
			}
		};
		return new ParserFileProcessingHookHandlers( $candidateProcessor );
	}

	/**
	 * @dataProvider provideDoParserAfterTidy
	 * @covers \PageImages\Hooks\ParserFileProcessingHookHandlers::onParserAfterTidy
	 */
	public function testDoParserAfterTidy(
		array $images,
		$expectedFreeFileName,
		$expectedNonFreeFileName
	) {
		$parser = $this->getParser( $images );
		$html = $this->getHtml( array_keys( $images ) );
		$handler = $this->getHandler( $images );
		$handler->onParserAfterTidy( $parser, $html );
		$properties = $parser->getOutput()->getPageProperties();

		if ( $expectedFreeFileName === null ) {
			$this->assertArrayNotHasKey( PageImages::PROP_NAME_FREE, $properties );
		} else {
			$this->assertSame( $expectedFreeFileName,
				$properties[PageImages::PROP_NAME_FREE] );
		}
		if ( $expectedNonFreeFileName === null ) {
			$this->assertArrayNotHasKey( PageImages::PROP_NAME, $properties );
		} else {
			$this->assertSame( $expectedNonFreeFileName, $properties[PageImages::PROP_NAME] );
		}
	}

	public static function provideDoParserAfterTidy() {
		return [
			// both images are non-free
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => false ],
				],
				null,
				'A.jpg'
			],
			// both images are free
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => true ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => true ],
				],
				'A.jpg',
				null
			],
			// one free (with a higher score), one non-free image
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => true ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => false ],
				],
				'A.jpg',
				null
			],
			// one non-free (with a higher score), one free image
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => true ],
				],
				'B.jpg',
				'A.jpg'
			]
		];
	}

	/**
	 * @dataProvider provideDoParserAfterTidy_lead
	 * @covers \PageImages\Hooks\ParserFileProcessingHookHandlers::onParserAfterTidy
	 */
	public function testDoParserAfterTidy_lead( bool $leadOnly ) {
		$candidates = [
			[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
			[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => true ],
		];

		$parser = $this->getParser( $candidates );
		$html = $this->getHtml( array_keys( $candidates ), 1 );
		$handler = $this->getHandler( $candidates, $leadOnly );
		$handler->onParserAfterTidy( $parser, $html );
		if ( $leadOnly ) {
			$this->assertNull(
				$parser->getOutput()->getPageProperty( PageImages::PROP_NAME_FREE ),
				'Only lead images are returned.' );
		} else {
			$this->assertIsString(
				$parser->getOutput()->getPageProperty( PageImages::PROP_NAME_FREE ),
				'All images are returned'
			);

		}
	}

	public static function provideDoParserAfterTidy_lead() {
		return [
			[ false ],
			[ true ]
		];
	}
}
