<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Value object for provider responses (translation + raw metadata payload).
 */
class VTransProviderResult
{
	/** @param array<string, mixed> $data */
	public function __construct(
		private string $translation,
		private array $data = [],
	) {}

	public function getTranslation(): string
	{
		return $this->translation;
	}

	/** @return array<string, mixed> */
	public function getData(): array
	{
		return $this->data;
	}
}