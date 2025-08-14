<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create modules table
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create submodules table
        Schema::create('submodules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            $table->string('title');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create roles table (if not exists)
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        // Create permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            $table->foreignId('submodule_id')->constrained('submodules')->onDelete('cascade');
            $table->enum('action', ['create', 'read', 'update', 'delete']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure unique permission per module-submodule-action combination
            $table->unique(['module_id', 'submodule_id', 'action']);
        });

        // Create role_permission pivot table
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();

            // Ensure unique role-permission combination
            $table->unique(['role_id', 'permission_id']);
        });


        // Seed the data
        $this->seedData();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('submodules');
        Schema::dropIfExists('modules');

        // Only drop roles if it was created by this migration
        // Comment out the next line if roles table existed before
        Schema::dropIfExists('roles');


    }

    /**
     * Seed the initial data
     */
    private function seedData(): void
    {
        DB::transaction(function () {
            // 1. Create Modules
            $modules = [
                [
                    'id' => 1,
                    'name' => 'Overview & Tickets',


                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 2,
                    'name' => 'User Management',


                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            DB::table('modules')->insert($modules);

            // 2. Create Submodules
            $submodules = [
                // Overview & Tickets Module
                [
                    'id' => 1,
                    'module_id' => 1,
                    'title' => 'Dashboard',

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 2,
                    'module_id' => 1,
                    'title' => 'Ticket Management',

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 3,
                    'module_id' => 1,
                    'title' => 'Business Systems',

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                // User Management Module
                [
                    'id' => 4,
                    'module_id' => 2,
                    'title' => 'Users',

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 5,
                    'module_id' => 2,
                    'title' => 'Roles',

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            DB::table('submodules')->insert($submodules);

            // 3. Create Admin Role
            $adminRole = [
                'id' => 1,
                'name' => 'Administrator',

                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('roles')->insert($adminRole);

            // 4. Create Permissions (CRUD for each submodule)
            $permissions = [];
            $permissionId = 1;
            $actions = ['create', 'read', 'update', 'delete'];

            foreach ($submodules as $submodule) {
                foreach ($actions as $action) {
                    $permissions[] = [
                        'id' => $permissionId,
                        'module_id' => $submodule['module_id'],
                        'submodule_id' => $submodule['id'],
                        'action' => $action,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $permissionId++;
                }
            }

            DB::table('permissions')->insert($permissions);

            // 5. Assign ALL permissions to Admin role
            $rolePermissions = [];
            for ($i = 1; $i <= count($permissions); $i++) {
                $rolePermissions[] = [
                    'role_id' => 1, // Admin role
                    'permission_id' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('role_permission')->insert($rolePermissions);

            // 6. Update existing users to have admin role (optional - you might want to do this manually)
            // Uncomment the next line if you want all existing users to become admins initially
            DB::table('users')->update(['role_id' => 1]);
        });
    }
};
