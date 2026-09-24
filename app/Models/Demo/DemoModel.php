<?php

namespace App\Models\Demo;

use App\Support\Demo\DemoDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every sandbox model.
 *
 * Isolation is at the database level: every subclass lives on the demo
 * connection (config/demo.php), a physically separate database from the
 * main LMS. A Demo panel resource bound to one of these classes cannot
 * read, write or delete a main-database row even if its query were
 * written wrongly — there is no main table on that connection to name.
 */
abstract class DemoModel extends Model
{
    public function getConnectionName(): ?string
    {
        return DemoDatabase::connectionName();
    }

    /**
     * Every demo table name is prefixed, which the reset service relies
     * on to know exactly which tables it may wipe.
     */
    public static function tableName(): string
    {
        return (new static)->getTable();
    }
}
