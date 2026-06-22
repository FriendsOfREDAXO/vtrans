<?php

namespace FriendsOfRedaxo\VTrans;

use rex;
use rex_sql;

/**
 * Connection model backed by rex_vtrans_connection table.
 */
class VTransConnection
{
	private const TABLE = 'vtrans_connection';
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	private int $id = 0;
	private string $key = '';
	private string $label = '';
	private string $provider = '';
	private ?string $apiKey = null;
	private string $apiUrl = '';
	private ?string $systemPrompt = null;
	private int $timeout = 30;
	private ?int $maxChars = null;
	private bool $debug = false;
	/** @var array<string, mixed> */
	private array $params = [];
	private bool $isDefault = false;
	private int $prio = 0;
	private bool $playground = true;
	private string $createdate = '';
	private string $createuser = '';
	private string $updatedate = '';
	private string $updateuser = '';

	/** @var list<self>|null */
	private static ?array $cache = null;

	public static function clearCache(): void
	{
		self::$cache = null;
	}

	/**
	 * @return list<self>
	 */
	public static function getAll(): array
	{
		if (null !== self::$cache) {
			return self::$cache;
		}

		$sql = rex_sql::factory();
		$sql->setQuery('SELECT * FROM ' . rex::getTable(self::TABLE) . ' ORDER BY prio ASC, id ASC');

		$connections = [];
		for ($i = 0; $i < $sql->getRows(); ++$i) {
			$connections[] = self::fromSql($sql);
			$sql->next();
		}

		self::$cache = $connections;
		return $connections;
	}

	/**
	 * @return list<self>
	 */
	public static function getAllPlayground(): array
	{
		return array_values(array_filter(self::getAll(), static fn (self $a) => $a->isPlayground()));
	}

	public static function getByKey(string $key): ?self
	{
		foreach (self::getAll() as $connection) {
			if ($connection->getKey() === $key) {
				return $connection;
			}
		}
		return null;
	}

	public static function getById(int $id): ?self
	{
		foreach (self::getAll() as $connection) {
			if ($connection->getId() === $id) {
				return $connection;
			}
		}
		return null;
	}

	/** Returns the connection explicitly marked as default (is_default = 1). */
	public static function getDefault(): ?self
	{
		foreach (self::getAll() as $connection) {
			if ($connection->isDefault()) {
				return $connection;
			}
		}
		return null;
	}

	/** Returns the default connection if it has playground enabled, otherwise the first playground connection. */
	public static function getDefaultPlayground(): ?self
	{
		$playground = self::getAllPlayground();
		if ([] === $playground) {
			return null;
		}
		$default = self::getDefault();
		if (null !== $default && $default->isPlayground()) {
			return $default;
		}
		return $playground[0];
	}

	/** Sets the given connection as default, clearing the flag on all other connections. */
	public static function setAsDefault(int $id): void
	{
		$table = rex::getTable(self::TABLE);
		$sql = rex_sql::factory();
		$sql->setQuery('UPDATE ' . $table . ' SET is_default = 0');
		$sql->setQuery('UPDATE ' . $table . ' SET is_default = 1 WHERE id = :id', ['id' => $id]);
		self::clearCache();
	}

	public static function getNextPrio(): int
	{
		$sql = rex_sql::factory();
		$sql->setQuery('SELECT MAX(prio) AS max_prio FROM ' . rex::getTable(self::TABLE));
		return ((int) $sql->getValue('max_prio')) + 1;
	}

	/**
	 * Move a connection up (lower prio value = higher in list).
	 * Swaps with the previous connection in prio order.
	 */
	public static function moveUp(int $id): void
	{
		$connections = self::getAll();
		$index = null;
		foreach ($connections as $i => $connection) {
			if ($connection->getId() === $id) {
				$index = $i;
				break;
			}
		}

		if (null === $index || $index <= 0) {
			return; // Already first or not found.
		}

		self::swapPrio($connections[$index], $connections[$index - 1]);
	}

	/**
	 * Move a connection down (higher prio value = lower in list).
	 * Swaps with the next connection in prio order.
	 */
	public static function moveDown(int $id): void
	{
		$connections = self::getAll();
		$index = null;
		foreach ($connections as $i => $connection) {
			if ($connection->getId() === $id) {
				$index = $i;
				break;
			}
		}

		if (null === $index || $index >= count($connections) - 1) {
			return; // Already last or not found.
		}

		self::swapPrio($connections[$index], $connections[$index + 1]);
	}

	private static function swapPrio(self $a, self $b): void
	{
		$prioA = $a->getPrio();
		$prioB = $b->getPrio();

		// If prios are equal, offset to ensure swap works.
		if ($prioA === $prioB) {
			$prioB = $prioA + 1;
		}

		$sql = rex_sql::factory();
		$table = rex::getTable(self::TABLE);

		$sql->setQuery('UPDATE ' . $table . ' SET prio = :prio WHERE id = :id', ['prio' => $prioB, 'id' => $a->getId()]);
		$sql->setQuery('UPDATE ' . $table . ' SET prio = :prio WHERE id = :id', ['prio' => $prioA, 'id' => $b->getId()]);

		self::clearCache();
	}

	public static function keyExists(string $key, ?int $excludeId = null): bool
	{
		foreach (self::getAll() as $connection) {
			if ($connection->getKey() === $key && (null === $excludeId || $connection->getId() !== $excludeId)) {
				return true;
			}
		}
		return false;
	}

	public function save(): void
	{
		$sql = rex_sql::factory();
		$sql->setTable(rex::getTable(self::TABLE));
		$sql->setValue('key', $this->key);
		$sql->setValue('label', $this->label);
		$sql->setValue('provider', $this->provider);
		$sql->setValue('api_key', $this->apiKey);
		$sql->setValue('api_url', $this->apiUrl);
		$sql->setValue('system_prompt', $this->systemPrompt);
		$sql->setValue('timeout', $this->timeout);
		$sql->setValue('max_chars', $this->maxChars);
		$sql->setValue('debug', (int) $this->debug);
		$sql->setValue('params', [] !== $this->params ? json_encode($this->params, self::JSON_FLAGS) : null);
		$sql->setValue('is_default', (int) $this->isDefault);
		$sql->setValue('prio', $this->prio);
		$sql->setValue('playground', (int) $this->playground);

		$login = (string) (rex::getUser()?->getLogin() ?? 'system');

		if ($this->id > 0) {
			$sql->setWhere(['id' => $this->id]);
			$sql->setValue('updateuser', $login);
			$sql->setRawValue('updatedate', 'NOW()');
			$sql->update();
		} else {
			$sql->setValue('createuser', $login);
			$sql->setRawValue('createdate', 'NOW()');
			$sql->setValue('updateuser', $login);
			$sql->setRawValue('updatedate', 'NOW()');
			$sql->insert();
			$this->id = (int) $sql->getLastId();
		}

		self::clearCache();
	}

	public function delete(): void
	{
		if ($this->id <= 0) {
			return;
		}

		$sql = rex_sql::factory();
		$sql->setTable(rex::getTable(self::TABLE));
		$sql->setWhere(['id' => $this->id]);
		$sql->delete();

		self::clearCache();
	}

	/** Build the config array expected by providers (modelData['config']).
	 * @return array<string, mixed>
	 */
	public function buildProviderConfig(): array
	{
		$config = [
			'api' => $this->provider,
			'label' => $this->label,
			'apiUrl' => $this->apiUrl,
			'apiKey' => $this->apiKey ?? '',
			'debug' => $this->debug,
			'systemPrompt' => $this->systemPrompt,
			'timeout' => $this->timeout,
			'maxChars' => $this->maxChars,
		];

		// Merge provider-specific params into flat config (provider normalizeConfig picks what it needs).
		foreach ($this->params as $k => $v) {
			$config[$k] = $v;
		}

		return $config;
	}

	/** Build the modelData array expected by VTrans/providers.
	 * @return array<string, mixed>
	 */
	public function toModelData(): array
	{
		return [
			'key' => $this->key,
			'config' => $this->buildProviderConfig(),
		];
	}

	// --- Getters ---

	public function getId(): int { return $this->id; }
	public function getKey(): string { return $this->key; }
	public function getLabel(): string { return $this->label; }
	public function getProvider(): string { return $this->provider; }
	public function getApiKey(): ?string { return $this->apiKey; }
	public function getApiUrl(): string { return $this->apiUrl; }
	public function getSystemPrompt(): ?string { return $this->systemPrompt; }
	public function getTimeout(): int { return $this->timeout; }
	public function getMaxChars(): ?int { return $this->maxChars; }
	public function isDebug(): bool { return $this->debug; }
	/** @return array<string, mixed> */
	public function getParams(): array { return $this->params; }
	public function isDefault(): bool { return $this->isDefault; }
	public function getPrio(): int { return $this->prio; }
	public function isPlayground(): bool { return $this->playground; }
	public function getCreatedate(): string { return $this->createdate; }
	public function getCreateuser(): string { return $this->createuser; }
	public function getUpdatedate(): string { return $this->updatedate; }
	public function getUpdateuser(): string { return $this->updateuser; }

	// --- Setters ---

	public function setKey(string $key): self { $this->key = $key; return $this; }
	public function setLabel(string $label): self { $this->label = $label; return $this; }
	public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
	public function setApiKey(?string $apiKey): self { $this->apiKey = $apiKey; return $this; }
	public function setApiUrl(string $apiUrl): self { $this->apiUrl = $apiUrl; return $this; }
	public function setSystemPrompt(?string $systemPrompt): self { $this->systemPrompt = $systemPrompt; return $this; }
	public function setTimeout(int $timeout): self { $this->timeout = max(1, $timeout); return $this; }
	public function setMaxChars(?int $maxChars): self { $this->maxChars = (null !== $maxChars && $maxChars > 0) ? $maxChars : null; return $this; }
	public function setDebug(bool $debug): self { $this->debug = $debug; return $this; }
	/** @param array<string, mixed> $params */
	public function setParams(array $params): self { $this->params = $params; return $this; }
	public function setDefault(bool $isDefault): self { $this->isDefault = $isDefault; return $this; }
	public function setPrio(int $prio): self { $this->prio = $prio; return $this; }
	public function setPlayground(bool $playground): self { $this->playground = $playground; return $this; }

	// --- Internal ---

	private static function fromSql(rex_sql $sql): self
	{
		$connection = new self();
		$connection->id = (int) $sql->getValue('id');
		$connection->key = (string) $sql->getValue('key');
		$connection->label = (string) $sql->getValue('label');
		$connection->provider = (string) $sql->getValue('provider');
		$connection->apiKey = $sql->getValue('api_key') !== null ? (string) $sql->getValue('api_key') : null;
		$connection->apiUrl = (string) $sql->getValue('api_url');
		$connection->systemPrompt = $sql->getValue('system_prompt') !== null ? (string) $sql->getValue('system_prompt') : null;
		$connection->timeout = (int) $sql->getValue('timeout');
		$rawMaxChars = $sql->getValue('max_chars');
		$connection->maxChars = (null !== $rawMaxChars && '' !== (string) $rawMaxChars && (int) $rawMaxChars > 0) ? (int) $rawMaxChars : null;
		$connection->debug = (bool) (int) $sql->getValue('debug');
		$connection->isDefault = (bool) (int) $sql->getValue('is_default');
		$connection->prio = (int) $sql->getValue('prio');
		$connection->playground = (bool) (int) $sql->getValue('playground');
		$connection->createdate = (string) $sql->getValue('createdate');
		$connection->createuser = (string) $sql->getValue('createuser');
		$connection->updatedate = (string) $sql->getValue('updatedate');
		$connection->updateuser = (string) $sql->getValue('updateuser');

		$paramsRaw = (string) $sql->getValue('params');
		if ('' !== trim($paramsRaw)) {
			$decoded = json_decode($paramsRaw, true);
			$connection->params = self::normalizeParams($decoded);
		}

		return $connection;
	}

	/** @return array<string, mixed> */
	private static function normalizeParams(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$normalized = [];
		foreach ($value as $key => $item) {
			$normalized[is_string($key) ? $key : (string) $key] = $item;
		}

		return $normalized;
	}

	public static function validateKey(string $key): ?string
	{
		$key = trim($key);
		if ('' === $key) {
			return 'Key darf nicht leer sein.';
		}
		if (strlen($key) > 191) {
			return 'Key darf maximal 191 Zeichen lang sein.';
		}
		if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
			return 'Key darf nur Kleinbuchstaben, Zahlen, Unterstrich und Bindestrich enthalten.';
		}
		return null;
	}
}
