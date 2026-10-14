<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cohabitation Keycloak : ajout de l'identité Keycloak (`sub`) sans toucher aux
 * id existants. `password` et `email` deviennent nullables (comptes créés à la
 * volée depuis Keycloak, sans mot de passe local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('keycloak_id')->nullable()->unique()->after('id');
            $table->string('username')->nullable()->after('name');
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les comptes créés via Keycloak n'ont pas de mot de passe local :
        // on leur attribue un hash inutilisable pour respecter NOT NULL.
        DB::table('users')->whereNull('password')->update(['password' => Hash::make(Str::random(64))]);
        foreach (DB::table('users')->whereNull('email')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['email' => "kc-{$id}@invalid.local"]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['keycloak_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['keycloak_id', 'username']);
            $table->string('password')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
