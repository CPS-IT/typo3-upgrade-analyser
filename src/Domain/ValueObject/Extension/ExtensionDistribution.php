<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject\Extension;

final readonly class ExtensionDistribution
{
    public function __construct(
        private string $type,
        private string $url,
    ) {
    }

    public static function fromArray(?array $array): ?self
    {
        if (null === $array) {
            return null;
        }

        return new self($array['type'], $array['url']);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
        ];
    }
}
