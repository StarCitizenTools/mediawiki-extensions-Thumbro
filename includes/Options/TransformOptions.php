<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Options;

/**
 * Immutable, resolved option set for a single Thumbro transform.
 *
 * Produced by {@see TransformOptionsResolver} from the per-input-MIME ThumbroOptions block.
 * `resizeOptions` are the vips load/resize options for the resize stage; `encodeList` is the
 * ordered list of encode-list entries (each `{encoder, when?, options}`) the
 * {@see \MediaWiki\Extension\Thumbro\Backend\EncodePipeline} routes over. The pipeline (not this
 * VO) interprets the entries, so option semantics live with each encoder, not here.
 */
class TransformOptions {

	/**
	 * @param array<string,string> $resizeOptions vips load/resize options
	 * @param array<int,array{encoder:string,when?:array<string,bool>,options?:array<string,string>}> $encodeList
	 * @param bool $setComment
	 */
	public function __construct(
		private readonly array $resizeOptions,
		private readonly array $encodeList,
		private readonly bool $setComment,
	) {
	}

	/** @return array<string,string> */
	public function resizeOptions(): array {
		return $this->resizeOptions;
	}

	/** @return array<int,array{encoder:string,when?:array<string,bool>,options?:array<string,string>}> */
	public function encodeList(): array {
		return $this->encodeList;
	}

	public function setComment(): bool {
		return $this->setComment;
	}
}
