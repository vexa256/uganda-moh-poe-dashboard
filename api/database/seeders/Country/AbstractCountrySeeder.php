<?php

declare(strict_types=1);

namespace Database\Seeders\Country;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * phase B — base for all country reference seeders.
 * Concrete seeders are parameterised by ISO2 via setIso2().
 */
abstract class AbstractCountrySeeder extends Seeder
{
    protected string $iso2 = 'UG';
    protected ?Command $cmd = null;

    public function setIso2(string $iso2): void
    {
        $this->iso2 = strtoupper($iso2);
    }

    public function setCommand(?Command $cmd): void
    {
        $this->cmd = $cmd;
    }

    protected function loadJson(string $name): array
    {
        $path = database_path("seeders/country/{$this->iso2}/data/{$name}");
        if (!is_file($path)) {
            throw new \RuntimeException("Missing seed JSON: {$path}");
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON shape in {$path}");
        }
        return $data;
    }

    protected function info(string $msg): void
    {
        if ($this->cmd) $this->cmd->info("  {$msg}");
    }

    /** Country profile — Uganda POE deployment. */
    protected function profile(): array
    {
        return ['name' => 'Uganda', 'iso3' => 'UGA', 'tz' => 'Africa/Kampala', 'dialing' => '+256'];
    }
}
