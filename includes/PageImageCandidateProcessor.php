<?php

namespace PageImages;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Deferred\LinksUpdate\LinksTable;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\MainConfigNames;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\TitleFactory;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Logic to collect, score and select page image candidates.
 *
 * @license WTFPL
 * @author Max Semenik
 * @author Thiemo Kreuz
 */
class PageImageCandidateProcessor {

	public function __construct(
		protected Config $config,
		private readonly RepoGroup $repoGroup,
		private readonly WANObjectCache $mainWANObjectCache,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ILBFactory $lbFactory,
		private readonly TitleFactory $titleFactory,
		private readonly LinksMigration $linksMigration,
	) {
	}

	/**
	 * Find the best images out of an array of candidates
	 *
	 * @param PageImageCandidate[] $images
	 * @return array{string|false,string|false} The best image, and the best free image
	 */
	public function findBestImages( array $images ) {
		if ( !$images ) {
			return [ false, false ];
		}

		// Determine the image scores

		$scores = [];
		$counter = 0;

		foreach ( $images as $image ) {
			$score = $this->getScore( $image, $counter++ );
			$fileName = $image->getFileName();
			$scores[$fileName] = max( $scores[$fileName] ?? -1, $score );
		}

		$bestImageName = false;
		$freeImageName = false;

		foreach ( $scores as $name => $score ) {
			if ( $score > 0 ) {
				if ( !$bestImageName || $score > $scores[$bestImageName] ) {
					$bestImageName = $name;
				}
				if ( ( !$freeImageName || $score > $scores[$freeImageName] ) && $this->isImageFree( $name ) ) {
					$freeImageName = $name;
				}
			}
		}
		return [ $bestImageName, $freeImageName ];
	}

	/**
	 * Returns true if only images in the lead section should be candidates
	 */
	public function isLeadSectionOnly(): bool {
		return (bool)$this->config->get( 'PageImagesLeadSectionOnly' );
	}

	/**
	 * Returns true if data for this title should be saved
	 */
	public function processThisTitle( PageReference $pageReference ): bool {
		static $flipped = null;
		$flipped ??= array_flip( $this->config->get( 'PageImagesNamespaces' ) );

		return isset( $flipped[$pageReference->getNamespace()] );
	}

	/**
	 * Estimates image size as displayed if not explicitly provided. We don't follow the core size
	 * calculation algorithm precisely because it's not required and editor's intentions are more
	 * important than the precise number.
	 */
	public function calcWidth( array &$params, File $file ): void {
		if ( isset( $params['handler']['width'] ) ) {
			return;
		}

		if ( isset( $params['handler']['height'] ) && $file->getHeight() > 0 ) {
			$params['handler']['width'] =
				$file->getWidth() * ( $params['handler']['height'] / $file->getHeight() );
		} elseif ( isset( $params['frame']['thumbnail'] )
			|| isset( $params['frame']['thumb'] )
			|| isset( $params['frame']['frameless'] )
		) {
			$thumbLimits = $this->config->get( MainConfigNames::ThumbLimits );
			$defaultUserOptions = $this->config->get( MainConfigNames::DefaultUserOptions );
			$params['handler']['width'] = $thumbLimits[$defaultUserOptions['thumbsize']]
				?? 250;
		} else {
			$params['handler']['width'] = $file->getWidth();
		}
	}

	/**
	 * Return score for image, the more the better, if it is less than zero,
	 * the image shouldn't be used for anything
	 *
	 * @param PageImageCandidate $image Associative array describing an image
	 * @param int $position Image order on page
	 * @return float
	 */
	protected function getScore( PageImageCandidate $image, int $position ) {
		$classes = preg_split( '/\s+/', $image->getFrameClass(), -1, PREG_SPLIT_NO_EMPTY );
		// Exclude images with class="notpageimage"
		if ( in_array( 'notpageimage', $classes ) ) {
			return -1000;
		}

		$pageImagesScores = $this->config->get( 'PageImagesScores' );
		if ( $image->getHandlerWidth() ) {
			// Standalone image
			$score = $this->scoreFromTable( $image->getHandlerWidth(), $pageImagesScores['width'] );
		} else {
			// From gallery
			$score = $this->scoreFromTable( $image->getFullWidth(), $pageImagesScores['galleryImageWidth'] );
		}

		if ( isset( $pageImagesScores['position'][$position] ) ) {
			$score += $pageImagesScores['position'][$position];
		}

		$ratio = intval( $this->getRatio( $image ) * 10 );
		$score += $this->scoreFromTable( $ratio, $pageImagesScores['ratio'] );

		// T91683: Prefer images with class="pageimage". We're simply adding to the current score rather than returning
		// earlier, so that the algorithm still helps decide which image to use when multiple have this class.
		if ( in_array( 'pageimage', $classes ) ) {
			$score += 1000;
		}

		$denylist = $this->getDenylist();
		if ( isset( $denylist[$image->getFileName()] ) ) {
			$score = -1000;
		}

		return $score;
	}

	/**
	 * Return score based on table of ranges
	 *
	 * @param int $value The number that the various bounds are compared against
	 * to calculate the score
	 * @param float[] $scores Table of scores for different ranges of $value
	 * @return float
	 */
	protected function scoreFromTable( int $value, array $scores ) {
		$lastScore = 0;

		// The loop stops at the *first* match, and therefore *requires* the input array keys to be
		// in increasing order.
		ksort( $scores, SORT_NUMERIC );
		foreach ( $scores as $upperBoundary => $score ) {
			$lastScore = $score;

			if ( $value <= $upperBoundary ) {
				break;
			}
		}

		if ( !is_numeric( $lastScore ) ) {
			wfLogWarning( 'The PageImagesScores setting must only contain numeric values!' );
		}

		return (float)$lastScore;
	}

	/**
	 * Check whether image's copyright allows it to be used freely.
	 *
	 * @param string $fileName Name of the image file
	 * @return bool
	 */
	protected function isImageFree( string $fileName ): bool {
		$file = $this->repoGroup->findFile( $fileName );
		if ( $file ) {
			// Process copyright metadata from CommonsMetadata, if present.
			// Image is considered free if the value is '0' or unset.
			return empty( $this->fetchFileMetadata( $file )['NonFree']['value'] );
		}
		return true;
	}

	/**
	 * Fetch file metadata
	 *
	 * @param File $file File to fetch metadata from
	 * @return array
	 */
	protected function fetchFileMetadata( File $file ): array {
		$format = new FormatMetadata;
		$context = new DerivativeContext( $format->getContext() );
		// we don't care about the language, and specifying singleLanguage is slightly faster
		$format->setSingleLanguage( true );
		// we don't care about the language, so avoid splitting the cache by selecting English
		$context->setLanguage( 'en' );
		$format->setContext( $context );
		return $format->fetchExtendedMetadata( $file );
	}

	/**
	 * Return width/height ratio of an image as displayed or 0 if not available
	 *
	 * @param PageImageCandidate $image
	 * @return float|int
	 */
	protected function getRatio( PageImageCandidate $image ) {
		$width = $image->getFullWidth();
		$height = $image->getFullHeight();
		return $width > 0 && $height > 0 ? $width / $height : 0;
	}

	/**
	 * Return a list of images denylisted from influencing this extension's output
	 *
	 * @return array<string,int> Flipped associative array in format "image BDB key" => int
	 * @throws Exception
	 */
	protected function getDenylist(): array {
		return $this->mainWANObjectCache->getWithSetCallback(
			$this->mainWANObjectCache->makeKey( 'pageimages-denylist' ),
			$this->config->get( 'PageImagesDenylistExpiry' ),
			function () {
				$list = [];
				foreach ( $this->config->get( 'PageImagesDenylist' ) as $source ) {
					array_push( $list, ...match ( $source['type'] ) {
						'db' => $this->getDbDenylist( $source['db'], $source['page'] ),
						'url' => $this->getUrlDenylist( $source['url'] ),
						default => throw new RuntimeException(
							"unrecognized image denylist type '{$source['type']}'"
						)
					} );
				}

				return array_flip( $list );
			}
		);
	}

	/**
	 * Return list of images linked by the given denylist page
	 *
	 * @param string|false $dbName Database name or false for current database
	 * @param string $page
	 * @return string[]
	 */
	private function getDbDenylist( $dbName, string $page ): array {
		$title = $this->titleFactory->newFromText( $page );
		if ( !$title || !$title->canExist() ) {
			return [];
		}

		$dbr = $this->lbFactory->getRemoteReplicaDatabase( $dbName, LinksTable::VIRTUAL_DOMAIN );
		$id = $dbr->newSelectQueryBuilder()
			->select( 'page_id' )
			->from( 'page' )
			->where( [ 'page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey() ] )
			->caller( __METHOD__ )->fetchField();
		if ( !$id ) {
			return [];
		}
		[ $blNamespace, $blTitle ] = $this->linksMigration->getTitleFields( 'pagelinks' );
		$queryInfo = $this->linksMigration->getQueryInfo( 'pagelinks' );

		return $dbr->newSelectQueryBuilder()
			->select( $blTitle )
			->tables( $queryInfo['tables'] )
			->joinConds( $queryInfo['joins'] )
			->where( [ 'pl_from' => (int)$id, $blNamespace => NS_FILE ] )
			->caller( __METHOD__ )->fetchFieldValues();
	}

	/**
	 * Return list of images on given remote denylist page.
	 *
	 * Not quite 100% bulletproof due to localised namespaces and so on.
	 * Though if you beat people if they add bad entries to the list... :)
	 *
	 * @param string $url
	 * @return string[]
	 */
	private function getUrlDenylist( string $url ): array {
		$list = [];
		$text = $this->httpRequestFactory->get( $url, [ 'timeout' => 3 ], __METHOD__ );
		$fileExtensions = $this->config->get( MainConfigNames::FileExtensions );
		$regex = '/\[\[:([^|\#]*?\.(?:' . implode( '|', $fileExtensions ) . '))/i';

		if ( $text && preg_match_all( $regex, $text, $matches ) ) {
			foreach ( $matches[1] as $s ) {
				$t = $this->titleFactory->makeTitleSafe( NS_FILE, $s );

				if ( $t ) {
					$list[] = $t->getDBkey();
				}
			}
		}

		return $list;
	}

}
