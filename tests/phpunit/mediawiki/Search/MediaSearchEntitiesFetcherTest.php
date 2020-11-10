<?php

namespace Wikibase\MediaInfo\Tests\MediaWiki\Search;

use MediaWikiTestCase;
use MultiHttpClient;
use Wikibase\MediaInfo\Search\MediaSearchEntitiesFetcher;

/**
 * @covers \Wikibase\MediaInfo\Search\MediaSearchEntitiesFetcher
 */
class MediaSearchEntitiesFetcherTest extends MediaWikiTestCase {
	protected function createMediaSearchEntitiesFetcher(): MediaSearchEntitiesFetcher {
		$mockMultiHttpClient = $this->createMock( MultiHttpClient::class );
		$mockMultiHttpClient->method( 'runMulti' )
			->willReturnCallback( function ( array $requests ) {
				foreach ( $requests as $i => $request ) {
					preg_match( '/&srsearch=(.*?)&/', $request['url'], $matches );
					$term = $matches[1];
					$filename = __DIR__ . "/../../data/entities_search/$term.json";
					$requests[$i]['response']['body'] = file_exists( $filename ) ? file_get_contents( $filename ) : '';
				}

				return $requests;
			} );

		return new MediaSearchEntitiesFetcher(
			$mockMultiHttpClient,
			'url-not-required-for-mock',
			'en'
		);
	}

	public function testGet() {
		$terms = [ 'cat', 'dog' ];

		$fetcher = $this->createMediaSearchEntitiesFetcher();
		$results = $fetcher->get( $terms );

		foreach ( $terms as $term ) {
			$this->assertArrayHasKey( $term, $results );
			$this->assertNotEmpty( $results[$term] );
			foreach ( $results[$term] as $result ) {
				$this->assertArrayHasKey( 'entityId', $result );
				$this->assertArrayHasKey( 'score', $result );
			}
		}
	}

	public function testGetRepeatedly() {
		$terms = [ 'cat', 'dog' ];

		$fetcher = $this->createMediaSearchEntitiesFetcher();

		for ( $i = 0; $i < 3; $i++ ) {
			$results = $fetcher->get( $terms );

			foreach ( $terms as $term ) {
				$this->assertArrayHasKey( $term, $results );
				$this->assertNotEmpty( $results[$term] );
				foreach ( $results[$term] as $result ) {
					$this->assertArrayHasKey( 'entityId', $result );
					$this->assertArrayHasKey( 'score', $result );
				}
			}
		}
	}

	public function testGetUnknownEntity() {
		$terms = [ 'donkey' ];

		$fetcher = $this->createMediaSearchEntitiesFetcher();
		$results = $fetcher->get( $terms );

		foreach ( $terms as $term ) {
			$this->assertArrayHasKey( $term, $results );
			$this->assertEmpty( $results[$term] );
		}
	}
}
