<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\GdBaseline;
use MediaWiki\Extension\Thumbro\Bench\Contenders\ImageMagickBaseline;
use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroGif;
use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips;
use RuntimeException;

class Orchestrator {
	/** @var Contender[] */
	private array $baselines;
	/** @var Contender[] */
	private array $candidates;

	public function __construct() {
		$this->baselines = [ new ImageMagickBaseline(), new GdBaseline() ];
		$this->candidates = [ new ThumbroVips(), new ThumbroGif() ];
	}

	/**
	 * @param array<int,array{path:string,mime:string,tier:string,animated:bool,frames:int,targets:int[]}> $corpus
	 * @return array<int,array<string,mixed>> rows for the reporter
	 */
	public function run( array $corpus, ?string $onlyMime, string $outDir ): array {
		$this->assertToolsFor( $corpus, $onlyMime );
		$rows = [];
		foreach ( $corpus as $entry ) {
			if ( $onlyMime !== null && $entry['mime'] !== $onlyMime ) {
				continue;
			}
			foreach ( $entry['targets'] as $w ) {
				$rows[] = $this->runOne( $entry, $w, $outDir );
			}
		}
		return $rows;
	}

	/**
	 * @param array{path:string,mime:string,tier:string,animated:bool,frames:int,targets:int[]} $entry
	 * @return array<string,mixed>
	 */
	private function runOne( array $entry, int $w, string $outDir ): array {
		$mime = $entry['mime'];
		$dir = $outDir . '/' . pathinfo( $entry['path'], PATHINFO_FILENAME ) . "_$w";
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$applicableBaselines = array_values( array_filter(
			$this->baselines, static fn ( Contender $c ) => $c->applies( $mime ) && $c->isAvailable()
		) );
		// Score each baseline's quality once (the gate needs it, and the reporter shows it).
		$baseResults = [];
		$baseQualities = [];
		foreach ( $applicableBaselines as $b ) {
			$res = $b->run( $entry['path'], $mime, $w, $dir );
			$baseResults[$b->name()] = $res;
			$baseQualities[$b->name()] = $res->available ? $this->scoreQuality( $res, $entry, $dir ) : null;
		}

		// Representative fixtures drive the dominance go/no-go; stress fixtures are checked
		// only against the hard safety caps (no baseline comparison, never a win/loss).
		$tier = $entry['tier'] ?? 'representative';
		$candResults = [];
		foreach ( $this->candidates as $c ) {
			if ( !$c->applies( $mime ) || !$c->isAvailable() ) {
				continue;
			}
			$res = $c->run( $entry['path'], $mime, $w, $dir );
			$quality = $res->available ? $this->scoreQuality( $res, $entry, $dir ) : null;
			$verdicts = [];
			$capsVerdict = null;
			if ( $res->available && $quality !== null ) {
				$gate = new AcceptanceGate( new GateThresholds(), $entry['animated'] );
				if ( $tier === 'stress' ) {
					$capsVerdict = $gate->evaluateCaps(
						$quality->mean, $res->wallMs ?? 0.0, $res->peakRssKb ?? 0
					);
				} else {
					foreach ( $baseResults as $name => $base ) {
						if ( !$base->available || $baseQualities[$name] === null ) {
							continue;
						}
						$verdicts[$name] = $gate->evaluate(
							$res->bytes ?? 0, $quality->mean, $res->wallMs ?? 0.0, $res->peakRssKb ?? 0,
							$base->bytes ?? 0, $baseQualities[$name]->mean, $base->wallMs ?? 0.0, $base->peakRssKb ?? 0
						);
					}
				}
			}
			$candResults[$c->name()] = [
				'result' => $res, 'quality' => $quality,
				'verdicts' => $verdicts, 'capsVerdict' => $capsVerdict,
			];
		}

		return [
			'source' => $entry['path'], 'mime' => $mime, 'width' => $w, 'tier' => $tier,
			'animated' => $entry['animated'], 'frames' => $entry['frames'],
			'baselines' => $baseResults, 'baselineQualities' => $baseQualities,
			'candidates' => $candResults, 'dir' => $dir,
		];
	}

	/**
	 * @param Result $res
	 * @param array{path:string,mime:string,tier:string,animated:bool,frames:int,targets:int[]} $entry
	 */
	private function scoreQuality( Result $res, array $entry, string $dir ): Quality {
		[ $w, $h ] = ImageDims::of( $res->thumbPath );
		$candDir = $dir . '/cand_' . $res->contender;
		$refDir = $dir . '/ref_' . $res->contender;
		foreach ( [ $candDir, $refDir ] as $d ) {
			if ( !is_dir( $d ) ) {
				mkdir( $d, 0777, true );
			}
		}
		$candFrames = Ssimulacra2::extractFrames( $res->thumbPath, $entry['frames'], $candDir );
		if ( $entry['animated'] ) {
			$refFrames = Reference::forFrames( $entry['path'], $w, $h, count( $candFrames ), $refDir );
		} else {
			$refFrames = [ Reference::forStatic( $entry['path'], $w, $h, $refDir ) ];
		}
		return Ssimulacra2::score( $refFrames, $candFrames );
	}

	/**
	 * @param array<int,array{path:string,mime:string,tier:string,animated:bool,frames:int,targets:int[]}> $corpus
	 */
	private function assertToolsFor( array $corpus, ?string $onlyMime ): void {
		$needed = [
			Ssimulacra2::$bin => 'tests/bench/bin/install-ssimulacra2.sh',
			'vips' => 'libvips-tools',
			'convert' => 'imagemagick',
		];
		foreach ( $needed as $bin => $pkg ) {
			if ( ToolLocator::path( (string)$bin ) === null ) {
				throw new RuntimeException( "Required tool '$bin' missing for the requested run (install: $pkg)" );
			}
		}
	}
}
