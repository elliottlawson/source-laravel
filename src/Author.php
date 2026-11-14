<?php

declare(strict_types=1);

namespace Source;

/**
 * A commit author: the name and email recorded in the commit.
 */
final readonly class Author
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    /**
     * Coerce whatever an app's author resolver returned into an Author.
     *
     * A user model (anything with name/email), an array, an Author, or null.
     */
    public static function from(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        $name = data_get($value, 'name');
        $email = data_get($value, 'email');

        if (! is_string($name) || $name === '' || ! is_string($email) || $email === '') {
            return null;
        }

        return new self($name, $email);
    }

    /**
     * @return array{name: string, email: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}
