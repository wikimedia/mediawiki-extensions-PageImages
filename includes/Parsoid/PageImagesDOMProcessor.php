<?php

namespace PageImages\Parsoid;

use PageImages\Hooks\ParserFileProcessingHookHandlers;
use PageImages\PageImageCandidate;
use PageImages\PageImageCandidateProcessor;
use PageImages\PageImages;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Ext\DOMDataUtils;
use Wikimedia\Parsoid\Ext\DOMProcessor;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Ext\WTUtils;

/**
 * Parsoid support for page_image and page_image_free page properties.
 * @see ParserFileProcessingHookHandlers for the legacy equivalent
 *
 * @license WTFPL
 */
class PageImagesDOMProcessor extends DOMProcessor {

	public function __construct(
		private readonly PageImageCandidateProcessor $candidateProcessor,
	) {
	}

	/** @inheritDoc */
	public function wtPostprocess(
		ParsoidExtensionAPI $extApi,
		DocumentFragment|Element $root,
		array $options
	): void {
		$namespace = $extApi->getPageConfig()->getLinkTarget()->getNamespace();
		if ( !$this->candidateProcessor->inProcessedNamespace( $namespace ) ) {
			return;
		}

		// Like the legacy parser, consider the whole page if there are no section edit links
		$leadSectionOnly = $this->candidateProcessor->isLeadSectionOnly() &&
			!DOMCompat::querySelector( $root, 'meta[property="mw:PageProp/noeditsection"]' );
		$candidates = [];
		$scoredCandidates = [];
		foreach ( $this->findMediaElements( $root ) as [ $element, $inLeadSection ] ) {
			$candidate = $this->makeCandidate( $extApi, $element );
			if ( $candidate ) {
				$candidates[] = $candidate;
				if ( !$leadSectionOnly || $inLeadSection ) {
					$scoredCandidates[] = $candidate;
				}
			}
		}

		$metadata = $extApi->getMetadata();
		// TODO remove this once we have validated that the page_image/page_image_free are aligned with legacy
		if ( $candidates ) {
			$metadata->setExtensionData( 'pageImages', array_map(
				static fn ( PageImageCandidate $candidate ) => $candidate->jsonSerialize(),
				$candidates
			) );
		}

		[ $bestImageName, $freeImageName ] = $this->candidateProcessor->findBestImages( $scoredCandidates );

		if ( $freeImageName ) {
			$metadata->setUnsortedPageProperty( PageImages::PROP_NAME_FREE, $freeImageName );
		}

		// Only store the image if it's not free. Free image (if any) has already been stored above.
		if ( $bestImageName && $bestImageName !== $freeImageName ) {
			$metadata->setUnsortedPageProperty( PageImages::PROP_NAME, $bestImageName );
		}
	}

	/**
	 * Find the media elements in document order.
	 * @return list<array{Element,bool}> The media elements, and whether they are in the lead section
	 */
	private function findMediaElements( DocumentFragment|Element $root ): array {
		$mediaElements = [];
		$inLeadSection = true;
		$this->collectMediaElements( $root, $mediaElements, $inLeadSection );
		return $mediaElements;
	}

	private function collectMediaElements(
		DocumentFragment|Element $node, array &$mediaElements, bool &$inLeadSection
	): void {
		// I'd prefer to have a 'h1, h2, h3, h4, h5, h6, [typeof*="mw:File"]' Zest selector rather than the
		// traversal. BUT: ordering nodes in DOM (via DOMNode::compareDocumentPosition) only comes in PHP 8.4,
		// which breaks the ordering with a multiple selector, so let's do the traversal ourselves instead.
		for ( $child = $node->firstChild; $child; $child = $child->nextSibling ) {
			if ( !$child instanceof Element ) {
				continue;
			}
			if ( str_contains( DOMCompat::getAttribute( $child, 'typeof' ) ?? '', 'mw:File' ) &&
				!$this->isMissingFile( $child )
			) {
				$mediaElements[] = [ $child, $inLeadSection ];
			}
			// In the legacy parser, the lead section ends at the first wikitext heading, detected by the section edit
			// link; if an image belongs to this heading, it is considered a part of the lead section.
			// This does look like a corner case, but let's implement it here as well - especially since there's a
			// test explicitly for that (PageImages with toc and image in heading), which probably checks more for the
			// absence of the comment in the TOC than for the fact that the image is part of the lead.
			$this->collectMediaElements( $child, $mediaElements, $inLeadSection );
			if ( preg_match( '/^h[1-6]$/', DOMUtils::nodeName( $child ) ) &&
				!WTUtils::isLiteralHTMLNode( $child )
			) {
				$inLeadSection = false;
			}
		}
	}

	/**
	 * Make a page image candidate from a media element, with the same parameters as the legacy parser.
	 */
	private function makeCandidate( ParsoidExtensionAPI $extApi, Element $element ): ?PageImageCandidate {
		$fileElement = DOMCompat::querySelector( $element, '.mw-file-element' );
		if ( !$fileElement ) {
			return null;
		}
		// Other extensions (e.g. Kartographer) can make media elements that are not files
		$resource = DOMCompat::getAttribute( $fileElement, 'resource' );
		if ( $resource === null ) {
			return null;
		}
		$title = $extApi->makeTitle( preg_replace( '#^\./#', '', $resource ), NS_FILE );
		if ( !$title ) {
			return null;
		}

		$fileWidth = (int)( DOMCompat::getAttribute( $fileElement, 'data-file-width' )
			?: DOMCompat::getAttribute( $fileElement, 'width' ) );
		$fileHeight = (int)( DOMCompat::getAttribute( $fileElement, 'data-file-height' )
			?: DOMCompat::getAttribute( $fileElement, 'height' ) );

		// The legacy parser gives no size and no frame parameters to gallery images
		$params = [];
		if ( !$this->isInGallery( $element ) ) {
			$params['frame']['class'] = $this->getEditorClasses( $element );
			$format = DOMUtils::matchTypeOf( $element, '#^mw:File/(Thumb|Frameless)$#D' );
			if ( $format === 'mw:File/Thumb' ) {
				$params['frame']['thumbnail'] = true;
			} elseif ( $format === 'mw:File/Frameless' ) {
				$params['frame']['frameless'] = true;
			}
			if ( !DOMUtils::hasClass( $element, 'mw-default-size' ) ) {
				// TODO: use the requested size, not the rendered size
				$params['handler']['width'] = (int)$fileElement->getAttribute( 'width' );
			}
		}
		$this->candidateProcessor->calcWidth( $params, $fileWidth, $fileHeight );
		$params['handler']['width'] = (int)$params['handler']['width'];

		return PageImageCandidate::newFromArray( [
			'filename' => $title->getDBkey(),
			'fullwidth' => $fileWidth,
			'fullheight' => $fileHeight,
		] + $params );
	}

	/**
	 * Get the classes from the class= option, like the legacy parser. Remove the classes that
	 * Parsoid adds to the wrapper for the size, border and alignment options.
	 */
	private function getEditorClasses( Element $element ): string {
		$classes = preg_split( '/\s+/', DOMCompat::getAttribute( $element, 'class' ) ?? '', -1, PREG_SPLIT_NO_EMPTY );
		return implode( ' ', preg_grep(
			'/^mw-(?:default-size|image-border|halign-.+|valign-.+)$/', $classes, PREG_GREP_INVERT
		) );
	}

	private function isInGallery( Element $element ): bool {
		for ( $node = $element->parentNode; $node instanceof Element; $node = $node->parentNode ) {
			if ( DOMUtils::hasTypeOf( $node, 'mw:Extension/gallery' ) ) {
				return true;
			}
		}
		return false;
	}

	private function isMissingFile( Element $element ): bool {
		if ( !DOMUtils::hasTypeOf( $element, 'mw:Error' ) ) {
			return false;
		}
		foreach ( DOMDataUtils::getDataMw( $element )->errors ?? [] as $error ) {
			if ( $error->key === 'apierror-filedoesnotexist' ) {
				return true;
			}
		}
		return false;
	}

}
