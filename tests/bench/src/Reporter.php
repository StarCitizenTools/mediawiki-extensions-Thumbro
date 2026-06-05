<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class Reporter {
	/** @param array<int,array<string,mixed>> $rows */
	public function writeJson( array $rows, string $outDir ): string {
		$path = $outDir . '/results.json';
		file_put_contents( $path, json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, 512 ) );
		return $path;
	}

	/** @param array<int,array<string,mixed>> $rows */
	public function printTable( array $rows ): void {
		foreach ( $rows as $row ) {
			printf( "\n%s  @%dpx  (%s%s)\n", basename( $row['source'] ), $row['width'],
				$row['mime'], $row['animated'] ? ", {$row['frames']}f animated" : '' );
			foreach ( $row['baselines'] as $name => $r ) {
				printf( "  baseline %-4s  %s\n", $name, $this->fmt( $r, null ) );
			}
			foreach ( $row['candidates'] as $name => $c ) {
				printf( "  %-13s %s\n", $name, $this->fmt( $c['result'], $c['quality'] ) );
				foreach ( $c['verdicts'] as $base => $v ) {
					$extra = $v->reasons ? ' reasons=' . implode( ',', $v->reasons ) : '';
					$extra .= $v->flags ? ' flags=' . implode( ',', $v->flags ) : '';
					printf( "      vs %-4s: %s%s\n", $base, $v->verdict->value, $extra );
				}
			}
		}
	}

	private function fmt( Result $r, ?Quality $q ): string {
		if ( !$r->available ) {
			return 'UNAVAILABLE (' . $r->error . ')';
		}
		$s = sprintf( '%8d B  %6.0f ms  %7d KB', $r->bytes, $r->wallMs, $r->peakRssKb );
		if ( $q !== null ) {
			$s .= sprintf( '  SSIM2 %5.1f (%s)', $q->mean, $q->band() );
		}
		return $s;
	}
}
