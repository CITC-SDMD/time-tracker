<?php

namespace App\Support;

// which organization the current request works inside. Set by the `org` middleware right after the person is
// known (their own organization, or, for a superadmin opening an office, the one in the URL) and cleared at the
// start and end of every request. The BelongsToOrganization models filter by it and fill it in on create, so a
// query that forgets the filter still cannot reach another office. It is null before sign-in (login, password
// reset) and in the console, where the code that runs must name the organization itself.
final class OrganizationContext
{
    private ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function set(?int $id): void
    {
        $this->id = $id;
    }

    public function clear(): void
    {
        $this->id = null;
    }

    /** Runs $callback inside organization $id and puts the previous context back afterwards. */
    public function within(int $id, callable $callback): mixed
    {
        $previous = $this->id;
        $this->id = $id;
        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
