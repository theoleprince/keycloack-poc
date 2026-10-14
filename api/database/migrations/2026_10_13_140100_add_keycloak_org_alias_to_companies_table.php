<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien société <-> Organization Keycloak (alias de l'organisation, tel qu'il
 * apparaît dans le claim `organization` du jeton).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('keycloak_org_alias')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['keycloak_org_alias']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('keycloak_org_alias');
        });
    }
};
