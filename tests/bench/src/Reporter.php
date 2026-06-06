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

	/** Plain-language labels for the gate's soft-budget flags. */
	private const FLAG_LABELS = [
		'memory-regression' => 'more memory',
		'time-regression' => 'slower',
	];

	/** @param array<int,array<string,mixed>> $rows */
	public function printTable( array $rows ): void {
		$isStress = static fn ( array $r ): bool => ( $r['tier'] ?? 'representative' ) === 'stress';
		$representative = array_filter( $rows, static fn ( array $r ): bool => !$isStress( $r ) );
		$stress = array_filter( $rows, $isStress );

		// summary[baseline] = [ win, trade, loss ] — representative tier only.
		$summary = [];
		if ( $representative !== [] ) {
			print "\n=== REPRESENTATIVE — go/no-go vs the MediaWiki baseline ===\n";
			foreach ( $representative as $row ) {
				$this->printRow( $row, $summary );
			}
		}
		if ( $stress !== [] ) {
			print "\n=== STRESS — safety caps only, never a win/loss ===\n";
			foreach ( $stress as $row ) {
				$this->printStressRow( $row );
			}
		}
		$this->printSummary( $summary );
	}

	/**
	 * Stress-tier row: show the contenders, then PASS / CAP-BREACH for the candidate against the
	 * hard safety caps. No baseline dominance — a stress fixture can never be a win or a loss.
	 *
	 * @param array<string,mixed> $row
	 */
	private function printStressRow( array $row ): void {
		printf( "\n%s · %dpx · %s%s\n",
			basename( $row['source'] ), $row['width'], $row['mime'],
			$row['animated'] ? " · {$row['frames']}f animated" : '' );
		printf( "  %-15s %9s  %-24s %8s  %7s\n", 'contender', 'size', 'SSIM2', 'time', 'peak RSS' );

		foreach ( $row['baselines'] as $name => $r ) {
			$bq = $row['baselineQualities'][$name] ?? null;
			$this->printContenderRow( strtoupper( $name ) . ' (context)', $r, $bq );
		}
		foreach ( $row['candidates'] as $name => $c ) {
			$this->printContenderRow( $name, $c['result'], $c['quality'] );
			if ( $c['capsVerdict'] !== null ) {
				printf( "    %s\n", $this->capsLine( $c['capsVerdict'], $c['quality'] ) );
			}
		}
	}

	/** PASS / CAP-BREACH line for a stress candidate. */
	private function capsLine( GateResult $g, ?Quality $q ): string {
		if ( $g->verdict === Verdict::PASS ) {
			return '✓ PASS — within every safety cap';
		}
		$breaches = [];
		foreach ( $g->reasons as $r ) {
			$breaches[] = match ( $r ) {
				'quality-floor' => $q !== null
					? sprintf( 'quality %.1f below the floor', $q->mean )
					: 'quality below the floor',
				'time-ceiling' => 'over the time ceiling',
				'rss-ceiling' => 'over the memory ceiling',
				default => $r,
			};
		}
		return '✗ CAP-BREACH — ' . implode( '; ', $breaches );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,array<string,int>> &$summary
	 */
	private function printRow( array $row, array &$summary ): void {
		printf( "\n%s · %dpx · %s%s\n",
			basename( $row['source'] ), $row['width'], $row['mime'],
			$row['animated'] ? " · {$row['frames']}f animated" : '' );
		printf( "  %-15s %9s  %-24s %8s  %7s\n", 'contender', 'size', 'SSIM2', 'time', 'peak RSS' );

		foreach ( $row['baselines'] as $name => $r ) {
			$label = strtoupper( $name ) . ' (baseline)';
			$this->printContenderRow( $label, $r, $row['baselineQualities'][$name] ?? null );
		}
		foreach ( $row['candidates'] as $name => $c ) {
			$this->printContenderRow( $name, $c['result'], $c['quality'] );
			foreach ( $c['verdicts'] as $base => $v ) {
				printf( "    vs %-3s  %s\n", strtoupper( $base ),
					$this->verdictLine( $v, $c['result'], $row['baselines'][$base], $c['quality'] ) );
				$bucket = match ( $v->verdict ) {
					Verdict::PASS => 'win',
					Verdict::FAIL => 'loss',
					Verdict::INCOMPARABLE => 'trade',
				};
				$summary[$base][$bucket] = ( $summary[$base][$bucket] ?? 0 ) + 1;
			}
		}
	}

	private function printContenderRow( string $label, Result $r, ?Quality $q ): void {
		if ( !$r->available ) {
			printf( "  %-15s  UNAVAILABLE (%s)\n", $label, trim( $r->error ) );
			return;
		}
		$ssim = $q !== null ? sprintf( '%5.1f %s', $q->mean, $q->band() ) : '-';
		printf( "  %-15s %7d B  %-24s %5.0f ms  %4d MB\n",
			$label, $r->bytes, $ssim, $r->wallMs, (int)round( $r->peakRssKb / 1024 ) );
	}

	/** A symbol + plain-language verdict for one candidate-vs-baseline comparison. */
	private function verdictLine( GateResult $g, Result $cand, Result $base, Quality $candQ ): string {
		[ $symbol, $word ] = match ( $g->verdict ) {
			Verdict::PASS => [ '✓', 'WIN' ],
			Verdict::FAIL => [ '✗', 'LOSS' ],
			Verdict::INCOMPARABLE => [ '~', 'TRADE-OFF' ],
		};
		return sprintf( '%s %-9s — %s', $symbol, $word, $this->detail( $g, $cand, $base, $candQ ) );
	}

	private function detail( GateResult $g, Result $cand, Result $base, Quality $candQ ): string {
		// Hard-constraint failures explain themselves; report the breach.
		if ( in_array( 'quality-floor', $g->reasons, true ) ) {
			return sprintf( 'quality %.1f is below the minimum floor', $candQ->mean );
		}
		if ( in_array( 'time-ceiling', $g->reasons, true ) ) {
			return 'over the hard time ceiling';
		}
		if ( in_array( 'rss-ceiling', $g->reasons, true ) ) {
			return 'over the hard memory ceiling';
		}

		$size = $this->sizeDelta( $cand->bytes, $base->bytes );
		if ( in_array( 'baseline-dominates', $g->reasons, true ) ) {
			return "$size; the baseline dominates (no worse on size and quality)";
		}

		// PASS / INCOMPARABLE: size, then the quality relation, then any soft trade-offs.
		$quality = in_array( 'quality-below-baseline', $g->flags, true )
			? 'lower quality'
			: 'comparable or better quality';
		$detail = "$size at $quality";
		$soft = [];
		foreach ( $g->flags as $f ) {
			if ( isset( self::FLAG_LABELS[$f] ) ) {
				$soft[] = self::FLAG_LABELS[$f];
			}
		}
		if ( $soft !== [] ) {
			$detail .= ', but ' . implode( ' and ', $soft );
		}
		return $detail;
	}

	private function sizeDelta( int $cand, int $base ): string {
		if ( $base <= 0 ) {
			return $cand . ' B';
		}
		$pct = (int)round( ( $cand - $base ) / $base * 100 );
		if ( $pct < 0 ) {
			return abs( $pct ) . '% smaller';
		}
		if ( $pct > 0 ) {
			return $pct . '% larger';
		}
		return 'same size';
	}

	/** @param array<string,array<string,int>> $summary */
	private function printSummary( array $summary ): void {
		if ( $summary === [] ) {
			return;
		}
		print "\n";
		foreach ( $summary as $base => $b ) {
			$win = $b['win'] ?? 0;
			$trade = $b['trade'] ?? 0;
			$loss = $b['loss'] ?? 0;
			printf( "Summary vs %s: %d win · %d trade-off · %d loss  (%d cases)\n",
				strtoupper( $base ), $win, $trade, $loss, $win + $trade + $loss );
		}
	}
}
