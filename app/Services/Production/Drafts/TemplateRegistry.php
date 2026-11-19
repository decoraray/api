<?php

namespace App\Services\Production\Drafts;

use App\Services\Production\Drafts\Templates;

/** Every design template, keyed by its key, in the order they are offered. */
class TemplateRegistry
{
    /** @var array<string, DesignTemplate> */
    private array $templates;

    public function __construct()
    {
        $this->templates = collect([
            new Templates\Cabinet(),
            new Templates\LowUnit(),
            new Templates\DrawerUnit(),
            new Templates\Desk(),
            new Templates\Table(),
            new Templates\BedFrame(),
            new Templates\WallShelf(),
            new Templates\Mirror(),
        ])->keyBy(fn (DesignTemplate $t) => $t->key())->all();
    }

    /** @return array<string, DesignTemplate> */
    public function all(): array
    {
        return $this->templates;
    }

    public function get(?string $key): ?DesignTemplate
    {
        return $this->templates[$key] ?? null;
    }

    public function keys(): array
    {
        return array_keys($this->templates);
    }

    /** Templates for the admin: keys, names and parameters. No cost. */
    public function present(): array
    {
        return array_values(array_map(fn (DesignTemplate $t) => [
            'key' => $t->key(),
            'name' => $t->name(),
            'axis_rule' => $t->axisRule()->value,
            'params' => $t->params(),
        ], $this->templates));
    }
}
