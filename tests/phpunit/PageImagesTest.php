<?php

namespace PageImages\Tests;

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Tests\FileRepo\TestRepoTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use PageImages\PageImages;

/**
 * @covers \PageImages\PageImages
 *
 * @group PageImages
 * @group Database
 *
 * @license WTFPL
 * @author Thiemo Kreuz
 */
class PageImagesTest extends MediaWikiIntegrationTestCase {
	use PageImagesTestTrait;
	use TestRepoTrait;

	private static ?WikiPage $page;

	/**
	 * @before
	 */
	protected function fixtureSetUp(): void {
		$this->overrideConfigValues( [
			// T263770
			MainConfigNames::UseParsoidParser => false,
		] );
		$this->initTestRepoGroup();
		RequestContext::getMain()->setUser( $this->getTestUser()->getUser() );
		$this->importFileToTestRepo(
			MW_INSTALL_PATH . '/tests/phpunit/data/media/landscape-plain.jpg',
			'Landscape-plain.jpg'
		);

		self::$page = $this->getNonexistingTestPage();
		$this->editPage( self::$page, 'Hello [[File:Landscape-plain.jpg|thumb]]' );
	}

	/**
	 * @after
	 */
	protected function fixtureTearDown(): void {
		$this->destroyTestRepo();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::Server => 'http://wiki.test',
			MainConfigNames::CanonicalServer => 'http://wiki.test',
			// T263770
			MainConfigNames::UseParsoidParser => false,
			'PageImagesAPIDefaultLicense' => 'free',
		] );
		$this->clearHooks();
	}

	private function getInstance(): PageImages {
		return $this->getServiceContainer()->getService( 'PageImages.PageImages' );
	}

	public function testConstants() {
		$this->assertSame( 'page_image', PageImages::PROP_NAME );
		$this->assertSame( 'page_image_free', PageImages::PROP_NAME_FREE );
	}

	public function testGetImage_existingWithCandidate() {
		$this->assertInstanceOf( LocalFile::class, $this->getInstance()->getImage( self::$page->getTitle() ) );
	}

	public function testGetImage_nonExistingArticle() {
		$title = $this->getNonexistingTestPage()->getTitle();
		$this->assertNull( $this->getInstance()->getImage( $title ) );
	}

	public function testGetImage_nonExistingFilePage() {
		$title = $this->getNonexistingTestPage( 'File:Notfound.jpg' )->getTitle();
		$this->assertNull( $this->getInstance()->getImage( $title ) );
	}

	public function testGetImages_existingWithCandidate() {
		$actual = $this->getInstance()->getImages( [ self::$page->getId() ], 100 );
		$this->assertSame( [
			self::$page->getId() => [
				'pageid' => self::$page->getId(),
				'ns' => self::$page->getTitle()->getNamespace(),
				'title' => self::$page->getTitle()->getPrefixedText(),
				'thumbnail' => [
					'source' => 'http://wiki.test/w/thumb.php?f=Landscape-plain.jpg&width=100',
					'width' => 100,
					'height' => 75,
				],
			],
		], $actual );
	}

	public function testGetPropNames() {
		$this->assertSame(
			[ PageImages::PROP_NAME_FREE, PageImages::PROP_NAME ],
			PageImages::getPropNames( PageImages::LICENSE_ANY )
		);
		$this->assertSame(
			PageImages::PROP_NAME_FREE,
			PageImages::getPropNames( PageImages::LICENSE_FREE )
		);
	}

	public function testBeforePageDisplay_existingWithCandidate() {
		$this->overrideConfigValues( [
			'PageImagesOpenGraph' => true,
			'PageImagesOpenGraphFallbackImage' => false
		] );
		$outputPage = $this->createOutputPage( self::$page->getTitle() );
		$skinTemplate = new SkinTemplate();

		$this->getInstance()->onBeforePageDisplay( $outputPage, $skinTemplate );
		$this->assertSame( [
				[ 'og:image', 'http://wiki.test/w/images/b/b0/Landscape-plain.jpg' ],
				[ 'og:image:width', '1200' ],
				[ 'og:image:height', '900' ]
		], $outputPage->getMetaTags() );
	}

	public function testBeforePageDisplay_disabled() {
		$this->overrideConfigValues( [
			'PageImagesOpenGraph' => false,
			'PageImagesOpenGraphFallbackImage' => false
		] );
		$this->setService( 'RepoGroup', $this->createMockRepoGroup( [] ) );
		$outputPage = $this->createOutputPage();
		$skinTemplate = new SkinTemplate();

		$this->getInstance()->onBeforePageDisplay( $outputPage, $skinTemplate );
		$this->assertSame( [], $outputPage->getMetaTags() );
	}

	public function testBeforePageDisplay_nonExistingPage() {
		$this->overrideConfigValues( [
			'PageImagesOpenGraph' => true,
			'PageImagesOpenGraphFallbackImage' => false
		] );
		$this->setService( 'RepoGroup', $this->createMockRepoGroup( [] ) );
		$title = $this->getNonexistingTestPage()->getTitle();
		$outputPage = $this->createOutputPage( $title );
		$skinTemplate = new SkinTemplate();

		$this->getInstance()->onBeforePageDisplay( $outputPage, $skinTemplate );
		$this->assertSame( [], $outputPage->getMetaTags() );
	}

	public static function provideFallbacks() {
		return [
			[ 'http://wiki.test/example.png', '/example.png' ],
			[ 'http://wiki.test/img/default.png', '/img/default.png' ],
			[ 'https://example.org/example.png', 'https://example.org/example.png' ],
		];
	}

	/**
	 * @dataProvider provideFallbacks
	 */
	public function testBeforePageDisplay_fallback( $expected, $fallback ) {
		$this->overrideConfigValues( [
			'PageImagesOpenGraph' => true,
			'PageImagesOpenGraphFallbackImage' => $fallback
		] );
		$title = $this->getNonexistingTestPage()->getTitle();
		$outputPage = $this->createOutputPage( $title );
		$skinTemplate = new SkinTemplate();

		$this->getInstance()->onBeforePageDisplay( $outputPage, $skinTemplate );
		$this->assertSame( [
				[ 'og:image', $expected ]
		], $outputPage->getMetaTags() );
	}

	private function createOutputPage( ?Title $title = null ): OutputPage {
		$context = $this->createMock( IContextSource::class );
		$context->method( 'getTitle' )
			->willReturn( $title ?? Title::makeTitle( NS_SPECIAL, 'Blankpage/PageImagesTest' ) );
		$context->method( 'getRequest' )->willReturn( new FauxRequest() );
		$context->method( 'getConfig' )
			->willReturn( $this->getServiceContainer()->getMainConfig() );

		$outputPage = new OutputPage( $context );
		if ( $title ) {
			$page = new WikiPage( $title );
			$pout = $page->getParserOutput();
			if ( $pout ) {
				$outputPage->addParserOutputMetadata( $pout );
			}
		}
		return $outputPage;
	}

}
