<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject\Extension;

final readonly class ExtensionAuthor
{
    public function __construct(
        private string $name,
        private ?string $email,
    ) {
    }

    public static function fromArray(?array $array): ?self
    {
        if (null === $array) {
            return null;
        }

        return new self($array['name'], $array['email'] ?? null);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
