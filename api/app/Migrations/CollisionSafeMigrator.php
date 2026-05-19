<?php

namespace App\Migrations;

use Illuminate\Console\View\Components\Task;
use Illuminate\Database\Migrations\MigrationResult;
use Illuminate\Database\Migrations\Migrator as BaseMigrator;

class CollisionSafeMigrator extends BaseMigrator
{
    protected function runUp($file, $batch, $pretend)
    {
        $name = $this->getMigrationName($file);

        if (in_array($name, $this->repository->getRan(), true)) {
            $this->write(Task::class, $name, fn () => MigrationResult::Skipped->value);

            return;
        }

        parent::runUp($file, $batch, $pretend);
    }
}
