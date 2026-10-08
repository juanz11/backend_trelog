<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert the custom roles/permissions schema to spatie/laravel-permission.
     *
     * Keeps the existing `roles` and `permissions` tables (including the custom
     * display_name/description/module columns) and migrates the pivot data:
     *   role_user       -> model_has_roles
     *   role_permission -> role_has_permissions
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->string('guard_name')->default('web')->after('name');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['name', 'guard_name']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->string('guard_name')->default('web')->after('name');
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'],
                'role_has_permissions_permission_id_role_id_primary');
        });

        if (Schema::hasTable('role_user')) {
            DB::table('role_user')->orderBy('id')->each(function ($row) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $row->role_id,
                    'model_id' => $row->user_id,
                    'model_type' => 'App\\Models\\User',
                ]);
            });
        }

        if (Schema::hasTable('role_permission')) {
            DB::table('role_permission')->orderBy('id')->each(function ($row) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $row->permission_id,
                    'role_id' => $row->role_id,
                ]);
            });
        }

        Schema::dropIfExists('role_user');
        Schema::dropIfExists('role_permission');

        app('cache')->forget(config('permission.cache.key', 'spatie.permission.cache'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->orderBy('role_id')
            ->each(function ($row) {
                DB::table('role_user')->insertOrIgnore([
                    'user_id' => $row->model_id,
                    'role_id' => $row->role_id,
                ]);
            });

        DB::table('role_has_permissions')->orderBy('role_id')->each(function ($row) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $row->role_id,
                'permission_id' => $row->permission_id,
            ]);
        });

        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->dropColumn('guard_name');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->dropColumn('guard_name');
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
