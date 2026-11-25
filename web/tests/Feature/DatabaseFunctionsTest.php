<?php

namespace Tests\Feature;

use App\Support\DatabaseFunctions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PostgreSQL functions come from database/sql/, applied after migrating. */
class DatabaseFunctionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_routing_function_comes_from_its_sql_file(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only');
        }
        $body = DB::scalar("SELECT prosrc FROM pg_proc WHERE proname = 'softswitch_route'");
        $this->assertNotNull($body, 'softswitch_route exists after migrating');
        $this->assertStringContainsString(trim($body), (string) file_get_contents(database_path('sql/softswitch_route.sql')));
        // Not callable by everyone: only the roles the installer grants.
        $this->assertFalse((bool) DB::scalar("SELECT has_function_privilege('public', 'softswitch_route(bigint, text, uuid, timestamptz, integer)', 'EXECUTE')"));

        $this->assertSame(['softswitch_route.sql'], DatabaseFunctions::apply()); // applying again is harmless
    }

    public function test_migrations_neither_define_it_nor_load_each_other(): void
    {
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);
            $this->assertStringNotContainsString('FUNCTION softswitch_route(', $source, "{$name} defines softswitch_route; edit database/sql/softswitch_route.sql instead");
            $this->assertDoesNotMatchRegularExpression("~(require|include)(_once)?\\s*\\(?\\s*database_path\\('migrations~", $source, "{$name} loads another migration");
            $this->assertDoesNotMatchRegularExpression("~file_get_contents\\(database_path\\('migrations~", $source, "{$name} reads another migration's source");
        }
    }
}
