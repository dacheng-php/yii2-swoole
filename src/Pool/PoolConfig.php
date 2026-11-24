<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

use yii\base\InvalidConfigException;

final class PoolConfig
{
    public function __construct(
        public readonly int $maxActive = 20,
        public readonly int $minIdle = 0,
        public readonly float $waitTimeout = 3.0,
        public readonly float $maxIdleTime = 60.0,
        public readonly float $maxLifetime = 3600.0,
        public readonly float $idlePingInterval = 30.0
    ) {
        if ($this->maxActive < 1) {
            throw new InvalidConfigException('Pool "maxActive" must be >= 1.');
        }

        if ($this->minIdle < 0 || $this->minIdle > $this->maxActive) {
            throw new InvalidConfigException('Pool "minIdle" must be between 0 and "maxActive".');
        }

        if ($this->waitTimeout <= 0) {
            throw new InvalidConfigException('Pool "waitTimeout" must be > 0.');
        }

        if ($this->maxIdleTime < 0) {
            throw new InvalidConfigException('Pool "maxIdleTime" must be >= 0.');
        }

        if ($this->maxLifetime < 0) {
            throw new InvalidConfigException('Pool "maxLifetime" must be >= 0.');
        }

        if ($this->idlePingInterval < 0) {
            throw new InvalidConfigException('Pool "idlePingInterval" must be >= 0.');
        }
    }

    /**
     * @param array<string, mixed>|self $config
     */
    public static function resolve(array|self $config): self
    {
        return $config instanceof self ? $config : self::fromArray($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            maxActive: (int) ($config['maxActive'] ?? 20),
            minIdle: (int) ($config['minIdle'] ?? 0),
            waitTimeout: (float) ($config['waitTimeout'] ?? 3.0),
            maxIdleTime: (float) ($config['maxIdleTime'] ?? 60.0),
            maxLifetime: (float) ($config['maxLifetime'] ?? 3600.0),
            idlePingInterval: (float) ($config['idlePingInterval'] ?? 30.0),
        );
    }
}
