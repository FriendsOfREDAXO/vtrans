<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Value object for provider responses (translation + raw metadata payload).
 */
readonly class VTransProviderResult
{
	public function __construct(
		private readonly string $translation,
		private readonly array $data = [],
	) {}

	public function getTranslation(): string
	{
		return $this->translation;
	}

	public function getData(): array
	{
		return $this->data;
	}
}