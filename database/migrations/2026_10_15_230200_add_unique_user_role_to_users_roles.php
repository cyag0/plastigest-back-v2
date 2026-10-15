<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users_roles no tenía índice único: cada vez que se volvía a correr el
 * seeder (insertOrIgnore no ignora nada sin un índice único) el Super Admin
 * quedaba con su rol repetido, y salía repetido en /me y en Administración.
 * Se quitan los repetidos (se conserva el renglón más antiguo) y se agrega
 * unique(user_id, role_id).
 */
return new class extends Migration
{
    private const INDEX = 'users_roles_user_id_role_id_unique';

    public function up(): void
    {
        if (Schema::hasIndex('users_roles', self::INDEX)) {
            return;
        }

        $keep = DB::table('users_roles')
            ->selectRaw('MIN(id) as id')
            ->groupBy('user_id', 'role_id')
            ->pluck('id');

        DB::table('users_roles')->whereNotIn('id', $keep)->delete();

        Schema::table('users_roles', function (Blueprint $table) {
            $table->unique(['user_id', 'role_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasIndex('users_roles', self::INDEX)) {
            return;
        }

        // MySQL quita el índice propio de la llave foránea de user_id cuando
        // otro índice empieza por esa columna: hay que devolverlo antes de
        // quitar el único.
        $restoreUserIndex = !Schema::hasIndex('users_roles', ['user_id']);

        Schema::table('users_roles', function (Blueprint $table) use ($restoreUserIndex) {
            if ($restoreUserIndex) {
                $table->index('user_id');
            }
            $table->dropUnique(self::INDEX);
        });
    }
};
